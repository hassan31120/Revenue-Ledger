<?php

declare(strict_types=1);

use App\Actions\AllocateSubscriptionRevenue;
use App\Actions\ClaimInstructorPayout;
use App\Actions\ReconcilePayout;
use App\Actions\SettlePayout;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Exceptions\InvalidPayoutTransitionException;
use App\Jobs\ProcessInstructorPayout;
use App\Models\Course;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\Subscription;
use App\Services\Ledger;
use App\Services\PayoutRecorder;
use App\Support\LedgerEntryDraft;
use Illuminate\Support\Facades\DB;

function payableInstructor(int $grossMinor = 10000): Instructor
{
    $instructor = Instructor::factory()->create();
    $course = Course::factory()->for($instructor)->create();

    $subscription = Subscription::factory()
        ->grossMinor($grossMinor)
        ->platformFeeBps(3000)
        ->withCourses([$course])
        ->create();

    app(AllocateSubscriptionRevenue::class)->handle($subscription);

    return $instructor;
}

function claimFor(Instructor $instructor): ?Payout
{
    return app(ClaimInstructorPayout::class)->handle($instructor->id, 1);
}

function settle(Payout $payout): Payout
{
    return app(SettlePayout::class)->handle($payout);
}

function reconcile(Payout $payout): Payout
{
    return app(ReconcilePayout::class)->handle($payout);
}

function providerMode(string $mode): void
{
    config(['revenue.provider.mock_mode' => $mode]);
}

function debitCount(Payout $payout): int
{
    return LedgerEntry::where('entry_type', LedgerEntryType::Payout->value)
        ->where('source_id', $payout->id)
        ->count();
}

describe('a successful payout', function () {
    it('pays the instructor, debits once, and zeroes the balance', function () {
        providerMode('success');
        $instructor = payableInstructor();

        $payout = settle(claimFor($instructor));

        expect($payout->status)->toBe(PayoutStatus::Paid)
            ->and($payout->provider_reference)->toStartWith('mockpay_')
            ->and($payout->completed_at)->not->toBeNull()
            ->and($payout->amount_minor)->toBe(7000)
            ->and(debitCount($payout))->toBe(1)
            ->and(LedgerEntry::outstandingMinor($instructor->id))->toBe(0)
            ->and(LedgerEntry::paidMinor($instructor->id))->toBe(7000);

        $this->artisan('ledger:verify')->assertExitCode(0);
    });

    it('frees the instructor to be paid again once new revenue arrives', function () {
        providerMode('success');
        $instructor = payableInstructor();
        settle(claimFor($instructor));

        $course = Course::where('instructor_id', $instructor->id)->first();
        $subscription = Subscription::factory()->grossMinor(10000)->platformFeeBps(3000)
            ->withCourses([$course])->create();
        app(AllocateSubscriptionRevenue::class)->handle($subscription);

        $second = settle(claimFor($instructor));

        expect($second->id)->not->toBe(1)
            ->and($second->status)->toBe(PayoutStatus::Paid)
            ->and(Payout::count())->toBe(2)
            ->and(LedgerEntry::paidMinor($instructor->id))->toBe(14000);
    });
});

describe('a permanent provider failure', function () {
    it('records no debit and leaves the balance payable', function () {
        providerMode('permanent_failure');
        $instructor = payableInstructor();

        $payout = settle(claimFor($instructor));

        expect($payout->status)->toBe(PayoutStatus::Failed)
            ->and(debitCount($payout))->toBe(0)
            ->and($payout->provider_reference)->toBeNull()
            ->and(LedgerEntry::outstandingMinor($instructor->id))->toBe(7000)
            ->and(InstructorBalance::find($instructor->id)->outstanding_minor)->toBe(7000);
    });

    it('lets a later run open a new payout that succeeds', function () {
        providerMode('permanent_failure');
        $instructor = payableInstructor();
        $failed = settle(claimFor($instructor));

        providerMode('success');
        $retry = settle(claimFor($instructor));

        expect($retry->id)->not->toBe($failed->id)
            ->and($retry->status)->toBe(PayoutStatus::Paid)
            ->and(LedgerEntry::paidMinor($instructor->id))->toBe(7000)
            ->and(DB::table('mock_provider_payments')->count())->toBe(1);
    });

    it('uses a different idempotency key for the replacement payout', function () {
        providerMode('permanent_failure');
        $instructor = payableInstructor();
        $failed = settle(claimFor($instructor));

        providerMode('success');
        $retry = settle(claimFor($instructor));

        expect($retry->provider_idempotency_key)->not->toBe($failed->provider_idempotency_key);
    });
});

describe('a timeout where no money moved', function () {
    it('parks the payout as unknown rather than failed', function () {
        providerMode('timeout_before_success');
        $instructor = payableInstructor();

        $payout = settle(claimFor($instructor));

        expect($payout->status)->toBe(PayoutStatus::Unknown)
            ->and(debitCount($payout))->toBe(0)
            ->and($payout->reconcile_after)->not->toBeNull();
    });

    it('is resolved to failed once the grace period has passed', function () {
        providerMode('timeout_before_success');
        $instructor = payableInstructor();
        $payout = settle(claimFor($instructor));

        $payout->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        $resolved = reconcile($payout->fresh());

        expect($resolved->status)->toBe(PayoutStatus::Failed)
            ->and(debitCount($resolved))->toBe(0)
            ->and(LedgerEntry::outstandingMinor($instructor->id))->toBe(7000);
    });

    it('stays unknown while still inside the grace period', function () {
        providerMode('timeout_before_success');
        $instructor = payableInstructor();
        $payout = settle(claimFor($instructor));

        $resolved = reconcile($payout->fresh());

        expect($resolved->status)->toBe(PayoutStatus::Unknown);
    });
});

describe('a timeout AFTER the money moved', function () {
    it('never claims the instructor was paid', function () {
        providerMode('timeout_after_success');
        $instructor = payableInstructor();

        $payout = settle(claimFor($instructor));

        expect(DB::table('mock_provider_payments')->count())->toBe(1)

            ->and($payout->status)->toBe(PayoutStatus::Unknown)
            ->and(debitCount($payout))->toBe(0)
            ->and(LedgerEntry::paidMinor($instructor->id))->toBe(0);
    });

    it('freezes the instructor so nothing can pay them twice', function () {
        providerMode('timeout_after_success');
        $instructor = payableInstructor();
        settle(claimFor($instructor));

        expect(LedgerEntry::outstandingMinor($instructor->id))->toBe(7000);

        providerMode('success');
        $second = claimFor($instructor);

        expect($second)->toBeNull()
            ->and(Payout::count())->toBe(1)
            ->and(DB::table('mock_provider_payments')->count())->toBe(1);
    });

    it('resolves to paid with exactly one debit when reconciled', function () {
        providerMode('timeout_after_success');
        $instructor = payableInstructor();
        $payout = settle(claimFor($instructor));

        $resolved = reconcile($payout->fresh());

        expect($resolved->status)->toBe(PayoutStatus::Paid)
            ->and($resolved->provider_reference)->toStartWith('mockpay_')
            ->and(debitCount($resolved))->toBe(1)
            ->and(LedgerEntry::paidMinor($instructor->id))->toBe(7000)
            ->and(LedgerEntry::outstandingMinor($instructor->id))->toBe(0)
            ->and(DB::table('mock_provider_payments')->count())->toBe(1);

        $this->artisan('ledger:verify')->assertExitCode(0);
    });

    it('is unharmed by reconciling repeatedly', function () {
        providerMode('timeout_after_success');
        $instructor = payableInstructor();
        $payout = settle(claimFor($instructor));

        reconcile($payout->fresh());
        reconcile($payout->fresh());
        reconcile($payout->fresh());

        expect(debitCount($payout))->toBe(1)
            ->and(LedgerEntry::paidMinor($instructor->id))->toBe(7000);
    });

    it('records the full audit trail of what happened', function () {
        providerMode('timeout_after_success');
        $instructor = payableInstructor();
        $payout = settle(claimFor($instructor));
        reconcile($payout->fresh());

        $attempts = DB::table('payout_attempts')->where('payout_id', $payout->id)
            ->orderBy('id')->get();

        expect($attempts)->toHaveCount(2)
            ->and($attempts[0]->kind)->toBe('send')
            ->and($attempts[0]->outcome)->toBe('timeout')
            ->and($attempts[1]->kind)->toBe('status_lookup')
            ->and($attempts[1]->outcome)->toBe('success');
    });
});

describe('retries and duplicate execution', function () {
    it('does not pay twice when the same payout is settled twice', function () {
        providerMode('success');
        $instructor = payableInstructor();
        $payout = claimFor($instructor);

        settle($payout);
        settle($payout->fresh());

        expect(debitCount($payout))->toBe(1)
            ->and(DB::table('mock_provider_payments')->count())->toBe(1)
            ->and(LedgerEntry::paidMinor($instructor->id))->toBe(7000);
    });

    it('does not create a second payout when the job runs twice', function () {
        providerMode('success');
        $instructor = payableInstructor();

        (new ProcessInstructorPayout($instructor->id, 1))->handle(
            app(ClaimInstructorPayout::class), app(SettlePayout::class)
        );
        (new ProcessInstructorPayout($instructor->id, 1))->handle(
            app(ClaimInstructorPayout::class), app(SettlePayout::class)
        );

        expect(Payout::count())->toBe(1)
            ->and(LedgerEntry::where('entry_type', LedgerEntryType::Payout->value)->count())->toBe(1);
    });

    it('does not create a second payout when the command runs twice', function () {
        providerMode('success');
        $instructor = payableInstructor();

        $this->artisan('payouts:process', ['--min-amount' => 1])->assertExitCode(0);
        $this->artisan('payouts:process', ['--min-amount' => 1])->assertExitCode(0);

        expect(Payout::where('instructor_id', $instructor->id)->count())->toBe(1);
    });

    it('reuses the same provider idempotency key on every retry of one payout', function () {
        providerMode('timeout_after_success');
        $instructor = payableInstructor();
        $payout = settle(claimFor($instructor));

        $keyAfterTimeout = $payout->provider_idempotency_key;
        reconcile($payout->fresh());

        expect($payout->fresh()->provider_idempotency_key)->toBe($keyAfterTimeout)
            ->and(DB::table('mock_provider_payments')->where('idempotency_key', $keyAfterTimeout)->count())
            ->toBe(1);
    });
});

describe('a crashed worker', function () {
    it('leaves the payout recoverable rather than lost', function () {
        providerMode('success');
        $instructor = payableInstructor();
        $payout = claimFor($instructor);

        Payout::whereKey($payout->id)->update([
            'status' => PayoutStatus::Processing->value,
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('payouts:reconcile', ['--sync' => true, '--stale-minutes' => 1])
            ->assertExitCode(0);

        expect($payout->fresh()->status)->toBe(PayoutStatus::Unknown);
    });

    it('is resolved as paid if the provider had in fact taken the money', function () {
        providerMode('timeout_after_success');
        $instructor = payableInstructor();
        $payout = claimFor($instructor);

        try {
            settle($payout);
        } catch (Throwable) {
        }

        Payout::whereKey($payout->id)->update([
            'status' => PayoutStatus::Processing->value,
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('payouts:reconcile', ['--sync' => true, '--stale-minutes' => 1]);

        expect($payout->fresh()->status)->toBe(PayoutStatus::Paid)
            ->and(debitCount($payout))->toBe(1);
    });

    it('moves the payout to unknown — never failed — when the job fails', function () {
        providerMode('success');
        $instructor = payableInstructor();
        $payout = claimFor($instructor);

        (new ProcessInstructorPayout($instructor->id, 1))->failed(new RuntimeException('worker killed'));

        expect($payout->fresh()->status)->toBe(PayoutStatus::Unknown)
            ->and($payout->fresh()->status)->not->toBe(PayoutStatus::Failed);
    });
});

describe('a paid payout is final', function () {
    it('cannot transition to any other state', function () {
        expect(PayoutStatus::Paid->allowedTransitions())->toBe([])
            ->and(PayoutStatus::Paid->canTransitionTo(PayoutStatus::Pending))->toBeFalse()
            ->and(PayoutStatus::Paid->canTransitionTo(PayoutStatus::Failed))->toBeFalse();
    });

    it('refuses an attempt to reopen it', function () {
        providerMode('success');
        $instructor = payableInstructor();
        $payout = settle(claimFor($instructor));

        expect(fn () => app(PayoutRecorder::class)
            ->markFailed($payout->fresh(), 'should be impossible'))
            ->toThrow(InvalidPayoutTransitionException::class);
    });

    it('is not picked up again by the payout command', function () {
        providerMode('success');
        $instructor = payableInstructor();
        settle(claimFor($instructor));

        $this->artisan('payouts:process', ['--min-amount' => 1])->assertExitCode(0);

        expect(Payout::count())->toBe(1);
    });
});

describe('what is payable', function () {
    it('does not pay an instructor below the minimum', function () {
        providerMode('success');
        $instructor = payableInstructor();

        expect(app(ClaimInstructorPayout::class)->handle($instructor->id, 999999))->toBeNull();
    });

    it('does not pay an instructor with a negative balance', function () {
        $instructor = payableInstructor();

        app(Ledger::class)->post([
            LedgerEntryDraft::forInstructor(
                instructorId: $instructor->id,
                entryType: LedgerEntryType::RefundAdjustment,
                amountMinor: -9000,
                currency: 'EGP', sourceType: 'refund', sourceId: 1,
                idempotencyKey: 'refund:1:ins:'.$instructor->id,
            ),
        ]);

        expect(LedgerEntry::outstandingMinor($instructor->id))->toBe(-2000)
            ->and(claimFor($instructor))->toBeNull();
    });

    it('does not pay an instructor with no ledger history at all', function () {
        $instructor = Instructor::factory()->create();

        expect(claimFor($instructor))->toBeNull();
    });
});
