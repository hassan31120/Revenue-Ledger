<?php

declare(strict_types=1);

use App\Enums\LedgerEntryType;
use App\Models\Instructor;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use App\Services\Ledger;
use App\Support\LedgerEntryDraft;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function post(array $drafts): int
{
    return app(Ledger::class)->post($drafts);
}

function earning(Instructor $instructor, int $amountMinor, int $subscriptionId, ?string $key = null): LedgerEntryDraft
{
    return LedgerEntryDraft::forInstructor(
        instructorId: $instructor->id,
        entryType: LedgerEntryType::Earning,
        amountMinor: $amountMinor,
        currency: 'EGP',
        sourceType: 'subscription',
        sourceId: $subscriptionId,
        idempotencyKey: $key ?? "earning:sub:{$subscriptionId}:ins:{$instructor->id}",
    );
}

describe('immutability', function () {
    it('refuses to update a ledger entry, even in raw SQL', function () {
        $instructor = Instructor::factory()->create();
        post([earning($instructor, 5000, 1)]);

        expect(fn () => DB::statement('UPDATE ledger_entries SET amount_minor = 999999'))
            ->toThrow(QueryException::class);

        expect(LedgerEntry::first()->amount_minor)->toBe(5000);
    });

    it('refuses to delete a ledger entry, even in raw SQL', function () {
        $instructor = Instructor::factory()->create();
        post([earning($instructor, 5000, 1)]);

        expect(fn () => DB::statement('DELETE FROM ledger_entries'))
            ->toThrow(QueryException::class);

        expect(LedgerEntry::count())->toBe(1);
    });

    it('refuses an update attempted through Eloquent', function () {
        $instructor = Instructor::factory()->create();
        post([earning($instructor, 5000, 1)]);

        $entry = LedgerEntry::first();

        expect(fn () => $entry->update(['amount_minor' => 1]))->toThrow(QueryException::class);
    });
});

describe('deriving earned, paid and outstanding', function () {
    it('derives all three figures from the ledger alone', function () {
        $instructor = Instructor::factory()->create();

        post([
            earning($instructor, 7000, 1),
            earning($instructor, 3000, 2),
        ]);

        post([LedgerEntryDraft::forInstructor(
            instructorId: $instructor->id,
            entryType: LedgerEntryType::Payout,
            amountMinor: -4000,
            currency: 'EGP',
            sourceType: 'payout',
            sourceId: 1,
            idempotencyKey: 'payout:1',
        )]);

        expect(LedgerEntry::earnedMinor($instructor->id))->toBe(10000)
            ->and(LedgerEntry::paidMinor($instructor->id))->toBe(4000)
            ->and(LedgerEntry::outstandingMinor($instructor->id))->toBe(6000);
    });

    it('lets outstanding go negative when a clawback exceeds what is unpaid', function () {
        $instructor = Instructor::factory()->create();

        post([earning($instructor, 5000, 1)]);
        post([LedgerEntryDraft::forInstructor(
            instructorId: $instructor->id,
            entryType: LedgerEntryType::Payout,
            amountMinor: -5000,
            currency: 'EGP', sourceType: 'payout', sourceId: 1, idempotencyKey: 'payout:1',
        )]);
        post([LedgerEntryDraft::forInstructor(
            instructorId: $instructor->id,
            entryType: LedgerEntryType::RefundAdjustment,
            amountMinor: -2000,
            currency: 'EGP', sourceType: 'refund', sourceId: 1, idempotencyKey: 'refund:1:ins:'.$instructor->id,
        )]);

        // Already paid in full, then refunded: the instructor now owes the platform.
        expect(LedgerEntry::outstandingMinor($instructor->id))->toBe(-2000)
            ->and(LedgerEntry::earnedMinor($instructor->id))->toBe(5000)
            ->and(LedgerEntry::paidMinor($instructor->id))->toBe(5000);
    });
});

describe('the balance projection', function () {
    it('tracks the ledger exactly as entries are posted', function () {
        $instructor = Instructor::factory()->create();

        post([earning($instructor, 7000, 1)]);
        post([LedgerEntryDraft::forInstructor(
            instructorId: $instructor->id,
            entryType: LedgerEntryType::Payout,
            amountMinor: -3000,
            currency: 'EGP', sourceType: 'payout', sourceId: 1, idempotencyKey: 'payout:1',
        )]);

        $balance = InstructorBalance::find($instructor->id);

        expect($balance->total_earned_minor)->toBe(7000)
            ->and($balance->total_paid_minor)->toBe(3000)
            ->and($balance->outstanding_minor)->toBe(4000);
    });

    it('does not create a projection row for the platform account', function () {
        post([LedgerEntryDraft::forPlatform(
            entryType: LedgerEntryType::PlatformFee,
            amountMinor: 3000,
            currency: 'EGP', sourceType: 'subscription', sourceId: 1, idempotencyKey: 'platform_fee:sub:1',
        )]);

        expect(InstructorBalance::count())->toBe(0)
            ->and(LedgerEntry::count())->toBe(1);
    });
});

describe('idempotency', function () {
    it('writes the same financial event only once, however many times it is posted', function () {
        $instructor = Instructor::factory()->create();

        $first = post([earning($instructor, 5000, 1)]);
        $second = post([earning($instructor, 5000, 1)]);
        $third = post([earning($instructor, 5000, 1)]);

        expect($first)->toBe(1)
            ->and($second)->toBe(0)
            ->and($third)->toBe(0)
            ->and(LedgerEntry::count())->toBe(1);
    });

    it('does not double-count the projection when a duplicate is posted', function () {
        $instructor = Instructor::factory()->create();

        post([earning($instructor, 5000, 1)]);
        post([earning($instructor, 5000, 1)]);

        expect(InstructorBalance::find($instructor->id)->outstanding_minor)->toBe(5000);
    });

    it('still writes the new entries in a batch that also contains duplicates', function () {
        $instructor = Instructor::factory()->create();

        post([earning($instructor, 5000, 1)]);

        // A retry that covers the original event plus one more.
        $written = post([
            earning($instructor, 5000, 1),
            earning($instructor, 2000, 2),
        ]);

        expect($written)->toBe(1)
            ->and(LedgerEntry::count())->toBe(2)
            ->and(InstructorBalance::find($instructor->id)->outstanding_minor)->toBe(7000);
    });
});

describe('ledger:verify', function () {
    it('passes on a consistent ledger', function () {
        $instructor = Instructor::factory()->create();
        post([earning($instructor, 5000, 1)]);

        $this->artisan('ledger:verify')->assertExitCode(0);
    });

    it('detects a projection that has drifted from the ledger', function () {
        $instructor = Instructor::factory()->create();
        post([earning($instructor, 5000, 1)]);

        // Corrupt the cache — the ledger itself cannot be corrupted.
        InstructorBalance::where('instructor_id', $instructor->id)
            ->update(['outstanding_minor' => 999999]);

        $this->artisan('ledger:verify')->assertExitCode(1);
    });
});
