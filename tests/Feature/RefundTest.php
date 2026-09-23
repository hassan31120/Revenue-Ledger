<?php

declare(strict_types=1);

use App\Actions\AllocateSubscriptionRevenue;
use App\Actions\ClaimInstructorPayout;
use App\Actions\RefundSubscription;
use App\Actions\SettlePayout;
use App\Enums\LedgerEntryType;
use App\Enums\SubscriptionStatus;
use App\Exceptions\RefundException;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function refundableSubscription(int $count = 3, int $grossMinor = 12000): array
{
    $instructors = Instructor::factory()->count($count)->create();
    $courses = $instructors->map(fn (Instructor $i) => Course::factory()->for($i)->create());

    $start = Carbon::parse('2026-01-01');

    $subscription = Subscription::factory()
        ->grossMinor($grossMinor)
        ->platformFeeBps(3000)
        ->withCourses($courses)
        ->create([
            'purchased_at' => $start,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addDays(100),
        ]);

    app(AllocateSubscriptionRevenue::class)->handle($subscription);

    return [$subscription, $instructors];
}

function refund(Subscription $s, ?int $amountMinor = null, string $ref = 'rf_1', ?Carbon $asOf = null)
{
    return app(RefundSubscription::class)->handle(
        subscription: $s,
        providerRefundReference: $ref,
        amountMinor: $amountMinor,
        asOf: $asOf,
    );
}

function clawbackFor(Subscription $s, int $instructorId): int
{
    return -(int) LedgerEntry::where('entry_type', LedgerEntryType::RefundAdjustment->value)
        ->where('instructor_id', $instructorId)
        ->where('source_type', 'refund')
        ->whereIn('source_id', fn ($q) => $q->select('id')->from('refunds')->where('subscription_id', $s->id))
        ->sum('amount_minor');
}

describe('pro-rating by unused days', function () {
    it('returns the unused portion of the term', function () {
        [$subscription] = refundableSubscription(grossMinor: 12000);

        $refund = refund($subscription, asOf: Carbon::parse('2026-02-10'));

        expect($refund->amount_minor)->toBe(7200);
    });

    it('returns nothing once the term has run out', function () {
        [$subscription] = refundableSubscription();

        expect(fn () => refund($subscription, asOf: Carbon::parse('2027-01-01')))
            ->toThrow(RefundException::class);
    });

    it('returns everything when cancelled on day one', function () {
        [$subscription] = refundableSubscription(grossMinor: 12000);

        $refund = refund($subscription, asOf: Carbon::parse('2026-01-01'));

        expect($refund->amount_minor)->toBe(12000)
            ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Refunded);
    });
});

describe('who gives the money back', function () {
    it('claws back from instructors and the platform in the original proportions', function () {
        [$subscription, $instructors] = refundableSubscription(3, 12000);

        refund($subscription, 12000);

        foreach ($instructors as $instructor) {
            expect(clawbackFor($subscription, $instructor->id))->toBe(2800)
                ->and(LedgerEntry::outstandingMinor($instructor->id))->toBe(0);
        }

        $platformNet = (int) LedgerEntry::where('account_type', 'platform')->sum('amount_minor');
        expect($platformNet)->toBe(0);
    });

    it('exactly negates the original allocation on a full refund', function () {
        [$subscription, $instructors] = refundableSubscription(3, 10000);

        $before = $instructors->mapWithKeys(fn ($i) => [$i->id => LedgerEntry::earnedMinor($i->id)]);

        refund($subscription, 10000);

        foreach ($instructors as $instructor) {
            expect(clawbackFor($subscription, $instructor->id))->toBe($before[$instructor->id]);
        }
    });

    it('leaves the ledger summing to zero for a fully refunded subscription', function () {
        [$subscription] = refundableSubscription(3, 10001);

        refund($subscription, 10001);

        expect((int) LedgerEntry::sum('amount_minor'))->toBe(0);

        $this->artisan('ledger:verify')->assertExitCode(0);
    });
});

describe('several partial refunds', function () {
    it('lands on exactly the same totals as one refund of the same size', function () {
        [$subscription, $instructors] = refundableSubscription(3, 10000);
        [$other, $otherInstructors] = refundableSubscription(3, 10000);

        refund($subscription, 3000, 'rf_a');
        refund($subscription, 3000, 'rf_b');
        refund($subscription, 4000, 'rf_c');

        refund($other, 10000, 'rf_single');

        $piecewise = $instructors->map(fn ($i) => clawbackFor($subscription, $i->id))->sort()->values()->all();
        $single = $otherInstructors->map(fn ($i) => clawbackFor($other, $i->id))->sort()->values()->all();

        expect($piecewise)->toBe($single);
    });

    it('never claws back more than was originally allocated', function () {
        [$subscription, $instructors] = refundableSubscription(3, 10001);

        refund($subscription, 3337, 'rf_a');
        refund($subscription, 3332, 'rf_b');
        refund($subscription, 3332, 'rf_c');

        foreach ($instructors as $instructor) {
            $allocated = LedgerEntry::earnedMinor($instructor->id);
            $clawed = clawbackFor($subscription, $instructor->id);

            expect($clawed)->toBeLessThanOrEqual(
                $allocated,
                "Instructor {$instructor->id} was clawed back {$clawed} against an allocation of {$allocated}"
            );
        }

        expect((int) LedgerEntry::sum('amount_minor'))->toBe(0);
    });

    it('conserves money across many awkward refund sequences', function () {
        foreach ([[3, 10001, [1, 1, 9999]], [4, 49999, [11111, 22222, 16666]], [7, 100003, [33334, 33334, 33335]]] as [$n, $gross, $parts]) {
            [$subscription] = refundableSubscription($n, $gross);

            foreach ($parts as $i => $part) {
                refund($subscription, $part, "rf_{$gross}_{$i}");
            }

            $posted = (int) LedgerEntry::where('source_type', 'refund')
                ->whereIn('source_id', fn ($q) => $q->select('id')->from('refunds')->where('subscription_id', $subscription->id))
                ->sum('amount_minor');

            expect($posted)->toBe(-array_sum($parts));
        }

        $this->artisan('ledger:verify')->assertExitCode(0);
    });

    it('refuses to return more money than came in', function () {
        [$subscription] = refundableSubscription(3, 10000);

        refund($subscription, 9000, 'rf_a');

        expect(fn () => refund($subscription, 2000, 'rf_b'))
            ->toThrow(RefundException::class);

        expect((int) DB::table('refunds')->sum('amount_minor'))->toBe(9000);
    });
});

describe('refunding an instructor who was already paid', function () {
    it('drives the balance negative rather than rewriting history', function () {
        config(['revenue.provider.mock_mode' => 'success']);
        [$subscription, $instructors] = refundableSubscription(1, 10000);
        $instructor = $instructors->first();

        $payout = app(ClaimInstructorPayout::class)->handle($instructor->id, 1);
        app(SettlePayout::class)->handle($payout);

        expect(LedgerEntry::outstandingMinor($instructor->id))->toBe(0);

        refund($subscription, 10000);

        expect(LedgerEntry::outstandingMinor($instructor->id))->toBe(-7000)
            ->and(InstructorBalance::find($instructor->id)->outstanding_minor)->toBe(-7000)

            ->and(LedgerEntry::earnedMinor($instructor->id))->toBe(7000)
            ->and(LedgerEntry::paidMinor($instructor->id))->toBe(7000);
    });

    it('stops any further payout until the debt is covered', function () {
        config(['revenue.provider.mock_mode' => 'success']);
        [$subscription, $instructors] = refundableSubscription(1, 10000);
        $instructor = $instructors->first();

        app(SettlePayout::class)->handle(app(ClaimInstructorPayout::class)->handle($instructor->id, 1));
        refund($subscription, 10000);

        expect(app(ClaimInstructorPayout::class)->handle($instructor->id, 1))->toBeNull();
    });

    it('nets the debt against future earnings', function () {
        config(['revenue.provider.mock_mode' => 'success']);
        [$subscription, $instructors] = refundableSubscription(1, 10000);
        $instructor = $instructors->first();

        app(SettlePayout::class)->handle(app(ClaimInstructorPayout::class)->handle($instructor->id, 1));
        refund($subscription, 10000);

        $course = Course::where('instructor_id', $instructor->id)->first();
        $next = Subscription::factory()->grossMinor(20000)->platformFeeBps(3000)
            ->withCourses([$course])->create();
        app(AllocateSubscriptionRevenue::class)->handle($next);

        expect(LedgerEntry::outstandingMinor($instructor->id))->toBe(7000);

        $payout = app(ClaimInstructorPayout::class)->handle($instructor->id, 1);

        expect($payout->amount_minor)->toBe(7000);
    });
});

describe('history is never rewritten', function () {
    it('adds entries rather than modifying or deleting any', function () {
        [$subscription] = refundableSubscription(3, 10000);

        $before = LedgerEntry::orderBy('id')->get(['id', 'amount_minor', 'entry_type']);

        refund($subscription, 10000);

        $after = LedgerEntry::whereIn('id', $before->pluck('id'))->orderBy('id')
            ->get(['id', 'amount_minor', 'entry_type']);

        expect($after->toArray())->toBe($before->toArray())
            ->and(LedgerEntry::count())->toBeGreaterThan($before->count());
    });

    it('records every clawback against the refund that caused it', function () {
        [$subscription] = refundableSubscription(3, 10000);

        $refund = refund($subscription, 10000);

        $entries = LedgerEntry::where('source_type', 'refund')->where('source_id', $refund->id)->get();

        expect($entries)->toHaveCount(4)
            ->and($entries->every(fn ($e) => $e->entry_type === LedgerEntryType::RefundAdjustment))->toBeTrue();
    });

    it('refuses to record the same provider refund twice', function () {
        [$subscription] = refundableSubscription(3, 10000);

        refund($subscription, 1000, 'rf_duplicate');

        expect(fn () => refund($subscription, 1000, 'rf_duplicate'))
            ->toThrow(QueryException::class);

        expect(DB::table('refunds')->count())->toBe(1);
    });
});

it('refuses to refund a subscription that was never allocated', function () {
    $subscription = Subscription::factory()->create();

    expect(fn () => refund($subscription, 1000))->toThrow(RefundException::class);
});
