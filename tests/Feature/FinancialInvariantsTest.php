<?php

declare(strict_types=1);

use App\Actions\AllocateSubscriptionRevenue;
use App\Actions\ClaimInstructorPayout;
use App\Actions\ReconcilePayout;
use App\Actions\RefundSubscription;
use App\Actions\SettlePayout;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

function chaosScenario(): array
{
    $instructors = Instructor::factory()->count(6)->create();
    $courses = $instructors->map(fn (Instructor $i) => Course::factory()->for($i)->create());

    $allocate = app(AllocateSubscriptionRevenue::class);
    $subscriptions = collect();

    $shapes = [
        [1, 19999], [2, 49999], [3, 10001], [4, 179999],
        [5, 1], [6, 100003], [3, 0], [2, 7], [5, 123457],
    ];

    foreach ($shapes as $index => [$count, $gross]) {
        $subscription = Subscription::factory()
            ->grossMinor($gross)
            ->platformFeeBps([0, 1500, 3000, 4999, 10000][$index % 5])
            ->withCourses($courses->take($count))
            ->create(['payment_reference' => "pay_chaos_{$index}"]);

        $allocate->handle($subscription);
        $subscriptions->push($subscription);
    }

    return [$instructors, $subscriptions];
}

it('conserves every minor unit through a full chaotic lifecycle', function () {
    [$instructors, $subscriptions] = chaosScenario();

    $claim = app(ClaimInstructorPayout::class);
    $settle = app(SettlePayout::class);
    $reconcile = app(ReconcilePayout::class);

    $modes = ['success', 'timeout_after_success', 'permanent_failure', 'timeout_before_success'];

    foreach ($instructors as $index => $instructor) {
        config(['revenue.provider.mock_mode' => $modes[$index % 4]]);

        if ($payout = $claim->handle($instructor->id, 1)) {
            $settle->handle($payout);
        }
    }

    foreach (Payout::all() as $payout) {
        $settle->handle($payout->fresh());
    }

    Payout::query()->update(['created_at' => now()->subDay()]);

    foreach ([1, 2] as $pass) {
        foreach (Payout::query()->open()->get() as $payout) {
            $reconcile->handle($payout->fresh());
        }
    }

    $refund = app(RefundSubscription::class);

    foreach ($subscriptions->take(4) as $index => $subscription) {
        if ($subscription->gross_amount_minor < 2) {
            continue;
        }

        $refund->handle($subscription, "rf_chaos_{$index}", intdiv($subscription->gross_amount_minor, 3));
    }

    $grossIn = (int) Subscription::sum('gross_amount_minor');
    $refunded = (int) DB::table('refunds')->sum('amount_minor');
    $paidOut = -(int) LedgerEntry::where('entry_type', LedgerEntryType::Payout->value)->sum('amount_minor');
    $ledgerTotal = (int) LedgerEntry::sum('amount_minor');

    expect($ledgerTotal)->toBe(
        $grossIn - $refunded - $paidOut,
        "Money leaked: gross {$grossIn}, refunded {$refunded}, paid out {$paidOut}, ledger holds {$ledgerTotal}"
    );

    $providerMoved = (int) DB::table('mock_provider_payments')
        ->whereIn('idempotency_key', Payout::where('status', PayoutStatus::Paid->value)->pluck('provider_idempotency_key'))
        ->sum('amount_minor');

    expect($paidOut)->toBe($providerMoved, 'The ledger and the provider disagree about how much money moved');

    $paidPayouts = Payout::where('status', PayoutStatus::Paid->value)->get();

    foreach ($paidPayouts as $payout) {
        $debits = LedgerEntry::where('source_type', 'payout')->where('source_id', $payout->id)->get();

        expect($debits)->toHaveCount(1, "Payout #{$payout->id} produced ".$debits->count().' debits')
            ->and((int) $debits->first()->amount_minor)->toBe(-$payout->amount_minor);
    }

    foreach (Payout::whereNot('status', PayoutStatus::Paid->value)->get() as $payout) {
        expect(LedgerEntry::where('source_type', 'payout')->where('source_id', $payout->id)->count())
            ->toBe(0, "Payout #{$payout->id} is {$payout->status->value} but debited the ledger");
    }

    $openPerInstructor = Payout::query()->open()
        ->select('instructor_id', DB::raw('COUNT(*) as n'))
        ->groupBy('instructor_id')->pluck('n', 'instructor_id');

    expect($openPerInstructor->filter(fn ($n) => $n > 1))->toBeEmpty();

    $this->artisan('ledger:verify')->assertExitCode(0);
});

it('keeps the ledger balanced when every subscription is fully refunded', function () {
    [, $subscriptions] = chaosScenario();

    $refund = app(RefundSubscription::class);

    foreach ($subscriptions as $index => $subscription) {
        if ($subscription->gross_amount_minor === 0) {
            continue;
        }

        $refund->handle($subscription, "rf_full_{$index}", $subscription->gross_amount_minor);
    }

    expect((int) LedgerEntry::sum('amount_minor'))->toBe(0);

    $this->artisan('ledger:verify')->assertExitCode(0);
});

it('never lets an instructor be paid more than they earned', function () {
    config(['revenue.provider.mock_mode' => 'success']);
    [$instructors] = chaosScenario();

    $claim = app(ClaimInstructorPayout::class);
    $settle = app(SettlePayout::class);

    foreach ($instructors as $instructor) {
        if ($payout = $claim->handle($instructor->id, 1)) {
            $settle->handle($payout);
        }
    }

    foreach ($instructors as $instructor) {
        expect(LedgerEntry::paidMinor($instructor->id))
            ->toBeLessThanOrEqual(
                LedgerEntry::earnedMinor($instructor->id),
                "Instructor #{$instructor->id} was paid more than they earned"
            );
    }
});
