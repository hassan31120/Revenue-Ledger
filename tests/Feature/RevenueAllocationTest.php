<?php

declare(strict_types=1);

use App\Actions\AllocateSubscriptionRevenue;
use App\Enums\LedgerEntryType;
use App\Exceptions\RevenueAllocationException;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use Illuminate\Support\Collection;

/**
 * Build a subscription shared by $count instructors, one course each.
 *
 * @return array{0: Subscription, 1: Collection<int, Instructor>}
 */
function sharedSubscription(int $count, int $grossMinor, int $feeBps = 3000): array
{
    $instructors = Instructor::factory()->count($count)->create();
    $courses = $instructors->map(fn (Instructor $i) => Course::factory()->for($i)->create());

    $subscription = Subscription::factory()
        ->grossMinor($grossMinor)
        ->platformFeeBps($feeBps)
        ->withCourses($courses)
        ->create();

    return [$subscription, $instructors];
}

function allocate(Subscription $subscription)
{
    return app(AllocateSubscriptionRevenue::class)->handle($subscription);
}

describe('splitting a subscription', function () {
    it('credits every participating instructor and the platform', function () {
        [$subscription, $instructors] = sharedSubscription(3, 10050);

        allocate($subscription);

        expect(RevenueAllocation::count())->toBe(3)
            ->and(LedgerEntry::where('entry_type', LedgerEntryType::Earning->value)->count())->toBe(3)
            ->and(LedgerEntry::where('entry_type', LedgerEntryType::PlatformFee->value)->count())->toBe(1);

        foreach ($instructors as $instructor) {
            expect(LedgerEntry::earnedMinor($instructor->id))->toBe(2345);
        }

        expect((int) LedgerEntry::where('account_type', 'platform')->sum('amount_minor'))->toBe(3015);
    });

    it('accounts for every minor unit of the payment', function () {
        [$subscription] = sharedSubscription(3, 10000);

        allocate($subscription);

        $posted = (int) LedgerEntry::where('source_type', 'subscription')
            ->where('source_id', $subscription->id)
            ->sum('amount_minor');

        expect($posted)->toBe(10000);
    });

    it('hands the indivisible remainder to the lowest instructor id', function () {
        [$subscription, $instructors] = sharedSubscription(3, 10000);

        allocate($subscription);

        $sorted = $instructors->sortBy('id')->values();

        expect(LedgerEntry::earnedMinor($sorted[0]->id))->toBe(2334)
            ->and(LedgerEntry::earnedMinor($sorted[1]->id))->toBe(2333)
            ->and(LedgerEntry::earnedMinor($sorted[2]->id))->toBe(2333);
    });

    it('updates the balance projection for each instructor', function () {
        [$subscription, $instructors] = sharedSubscription(2, 10000);

        allocate($subscription);

        foreach ($instructors as $instructor) {
            $balance = InstructorBalance::find($instructor->id);

            expect($balance->total_earned_minor)->toBe(3500)
                ->and($balance->outstanding_minor)->toBe(3500)
                ->and($balance->total_paid_minor)->toBe(0);
        }
    });

    it('refuses to allocate a subscription that grants no courses', function () {
        $subscription = Subscription::factory()->create();

        expect(fn () => allocate($subscription))
            ->toThrow(RevenueAllocationException::class);
    });
});

describe('idempotency', function () {
    it('creates no duplicate allocations when run twice', function () {
        [$subscription] = sharedSubscription(3, 49999);

        allocate($subscription);
        allocate($subscription);
        allocate($subscription);

        expect(RevenueAllocation::count())->toBe(3)
            ->and(LedgerEntry::count())->toBe(4); // 3 earnings + 1 platform fee
    });

    it('does not inflate balances when run twice', function () {
        [$subscription, $instructors] = sharedSubscription(2, 10000);

        allocate($subscription);
        allocate($subscription);

        expect(InstructorBalance::find($instructors[0]->id)->outstanding_minor)->toBe(3500);
    });

    it('returns the identical split on every call', function () {
        [$subscription] = sharedSubscription(3, 49999);

        $first = allocate($subscription);
        $second = allocate($subscription);

        expect($first->instructorMinor)->toBe($second->instructorMinor)
            ->and($first->platformMinor)->toBe($second->platformMinor);
    });

    it('survives the command being run twice', function () {
        [$subscription] = sharedSubscription(3, 49999);

        $this->artisan('revenue:allocate')->assertExitCode(0);
        $this->artisan('revenue:allocate')->assertExitCode(0);

        expect(RevenueAllocation::count())->toBe(3);

        $this->artisan('ledger:verify')->assertExitCode(0);
    });
});

describe('the snapshot protects historical money', function () {
    it('stores the weights and fee that were actually applied', function () {
        [$subscription, $instructors] = sharedSubscription(2, 10000, feeBps: 2500);

        allocate($subscription);

        $allocation = RevenueAllocation::where('instructor_id', $instructors[0]->id)->first();

        expect($allocation->weight)->toBe(1)
            ->and($allocation->platform_fee_bps)->toBe(2500)
            ->and($allocation->amount_minor)->toBe(3750);
    });

    it('is unaffected by a later change to the platform commission', function () {
        [$subscription, $instructors] = sharedSubscription(2, 10000, feeBps: 3000);

        allocate($subscription);

        config(['revenue.platform_fee_bps' => 9000]);
        allocate($subscription); // a re-run under a new global fee

        expect(LedgerEntry::earnedMinor($instructors[0]->id))->toBe(3500)
            ->and(RevenueAllocation::where('instructor_id', $instructors[0]->id)->first()->platform_fee_bps)
            ->toBe(3000);
    });

    it('is unaffected by a course later moving to a different instructor', function () {
        [$subscription, $instructors] = sharedSubscription(2, 10000);

        allocate($subscription);

        // The catalog changes after the fact.
        $newOwner = Instructor::factory()->create();
        Course::where('instructor_id', $instructors[0]->id)->update(['instructor_id' => $newOwner->id]);

        expect(LedgerEntry::earnedMinor($instructors[0]->id))->toBe(3500)
            ->and(LedgerEntry::earnedMinor($newOwner->id))->toBe(0);
    });
});

describe('edge cases', function () {
    it('allocates a single-instructor subscription entirely to them', function () {
        [$subscription, $instructors] = sharedSubscription(1, 19999);

        allocate($subscription);

        // 30% of 19999 floors to 5999; the instructor takes the remaining 14000.
        expect(LedgerEntry::earnedMinor($instructors[0]->id))->toBe(14000)
            ->and((int) LedgerEntry::where('account_type', 'platform')->sum('amount_minor'))->toBe(5999);
    });

    it('handles a free subscription without inventing money', function () {
        [$subscription, $instructors] = sharedSubscription(2, 0);

        allocate($subscription);

        expect(LedgerEntry::earnedMinor($instructors[0]->id))->toBe(0)
            ->and((int) LedgerEntry::sum('amount_minor'))->toBe(0);

        $this->artisan('ledger:verify')->assertExitCode(0);
    });

    it('gives instructors nothing when the platform takes everything', function () {
        [$subscription, $instructors] = sharedSubscription(2, 10000, feeBps: 10000);

        allocate($subscription);

        expect(LedgerEntry::earnedMinor($instructors[0]->id))->toBe(0)
            ->and((int) LedgerEntry::where('account_type', 'platform')->sum('amount_minor'))->toBe(10000);
    });

    it('conserves money across many differently-shaped subscriptions', function () {
        foreach ([[2, 19999], [3, 49999], [4, 179999], [5, 1], [7, 100003]] as [$count, $gross]) {
            [$subscription] = sharedSubscription($count, $gross);
            allocate($subscription);

            $posted = (int) LedgerEntry::where('source_type', 'subscription')
                ->where('source_id', $subscription->id)
                ->sum('amount_minor');

            expect($posted)->toBe($gross, "Subscription of {$gross} across {$count} instructors did not balance");
        }

        $this->artisan('ledger:verify')->assertExitCode(0);
    });
});
