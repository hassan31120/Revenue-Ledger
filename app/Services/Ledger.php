<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\LedgerEntryType;
use App\Support\LedgerEntryDraft;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only way money is written into this system.
 *
 * Two responsibilities, and deliberately no domain knowledge beyond them:
 *
 *   1. Append entries, tolerating exact duplicates.
 *   2. Keep the instructor_balances projection in step, in the SAME transaction,
 *      so the projection can never describe a ledger state that was rolled back.
 *
 * Idempotency is delegated to the database. A caller re-running the same
 * financial event recomputes the same idempotency keys, the unique index rejects
 * them, and this class reports how many rows were genuinely new. That is a real
 * guarantee under concurrency; an application-level "does it already exist?"
 * check is not, because two workers can both read "no" before either writes.
 */
final class Ledger
{
    /**
     * Append a batch of entries atomically.
     *
     * @param  array<int, LedgerEntryDraft>  $drafts
     * @return int how many entries were actually written (0 means already posted)
     */
    public function post(array $drafts): int
    {
        if ($drafts === []) {
            return 0;
        }

        return DB::transaction(function () use ($drafts) {
            /** @var array<int, LedgerEntryDraft> $written */
            $written = [];
            $lastInsertId = 0;

            foreach ($drafts as $draft) {
                try {
                    $id = DB::table('ledger_entries')->insertGetId($draft->toRow());

                    $written[] = $draft;
                    $lastInsertId = max($lastInsertId, $id);
                } catch (UniqueConstraintViolationException) {
                    // This exact financial event is already recorded. In MySQL a
                    // failed statement does not abort the surrounding transaction,
                    // so the rest of the batch proceeds normally.
                    //
                    // Skipping is the correct outcome, not an error: it is what
                    // makes a retried job, a double-run command and a duplicated
                    // queue message all converge on the same ledger.
                    continue;
                }
            }

            if ($written !== []) {
                $this->applyToProjection($written, $lastInsertId);
            }

            return count($written);
        });
    }

    /**
     * Fold newly written entries into the running totals.
     *
     * Only entries that were actually inserted reach this point, so a duplicate
     * can never double-count a balance.
     *
     * @param  array<int, LedgerEntryDraft>  $written
     */
    private function applyToProjection(array $written, int $lastInsertId): void
    {
        /** @var array<int, array{earned:int, paid:int, outstanding:int}> $deltas */
        $deltas = [];

        foreach ($written as $draft) {
            if ($draft->accountType !== AccountType::Instructor) {
                continue; // The platform account has no projection row.
            }

            $id = $draft->instructorId;
            $deltas[$id] ??= ['earned' => 0, 'paid' => 0, 'outstanding' => 0];

            // Every entry type moves the outstanding balance, including clawbacks.
            $deltas[$id]['outstanding'] += $draft->amountMinor;

            if ($draft->entryType === LedgerEntryType::Earning) {
                $deltas[$id]['earned'] += $draft->amountMinor;
            }

            if ($draft->entryType === LedgerEntryType::Payout) {
                // Payout entries are stored negative; "paid" is reported positive.
                $deltas[$id]['paid'] -= $draft->amountMinor;
            }
        }

        foreach ($deltas as $instructorId => $delta) {
            // Ensure the row exists without racing another worker for it.
            DB::table('instructor_balances')->insertOrIgnore([
                'instructor_id' => $instructorId,
                'total_earned_minor' => 0,
                'total_paid_minor' => 0,
                'outstanding_minor' => 0,
                'last_ledger_entry_id' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Relative increments, never "set to a value computed earlier" — so
            // two concurrent transactions touching the same instructor compose
            // instead of clobbering one another.
            DB::table('instructor_balances')
                ->where('instructor_id', $instructorId)
                ->update([
                    'total_earned_minor' => DB::raw("total_earned_minor + {$delta['earned']}"),
                    'total_paid_minor' => DB::raw("total_paid_minor + {$delta['paid']}"),
                    'outstanding_minor' => DB::raw("outstanding_minor + {$delta['outstanding']}"),
                    'last_ledger_entry_id' => DB::raw("GREATEST(last_ledger_entry_id, {$lastInsertId})"),
                    'updated_at' => now(),
                ]);
        }
    }
}
