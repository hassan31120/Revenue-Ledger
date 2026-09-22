<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reserves an instructor's outstanding balance as a payout, or declines to.
 *
 * Concurrency is handled by two independent mechanisms, and the stronger one is
 * in the database:
 *
 *   1. The instructor's projection row is locked FOR UPDATE, so two workers
 *      looking at the same instructor are serialised. The lock is per-instructor,
 *      never global.
 *
 *   2. If that lock were removed entirely, the unique index on the payouts
 *      generated column would still reject the second open payout. The catch
 *      below is not a fallback for a rare case — it is the real guarantee.
 *
 * The provider is NOT called here. This method opens a transaction, and holding
 * an InnoDB row lock across a network call to a payment provider is how a payout
 * system turns into an outage.
 */
final class ClaimInstructorPayout
{
    public function handle(int $instructorId, ?int $minimumMinor = null): ?Payout
    {
        // A payout must always move a positive amount (the database enforces it),
        // so the floor is at least one minor unit however the config is set.
        $minimum = max(1, $minimumMinor ?? (int) config('revenue.payouts.minimum_minor'));

        try {
            return DB::transaction(function () use ($instructorId, $minimum) {
                $balance = DB::table('instructor_balances')
                    ->where('instructor_id', $instructorId)
                    ->lockForUpdate()
                    ->first();

                if ($balance === null) {
                    return null;
                }

                $outstanding = (int) $balance->outstanding_minor;

                // Covers the ordinary "nothing to pay" case and the refund case,
                // where the balance is negative because the instructor owes the
                // platform. A negative balance simply means no payout until future
                // earnings cover it.
                if ($outstanding < $minimum) {
                    return null;
                }

                // Cheap pre-check. Not the guarantee — the unique index is.
                if (Payout::query()->where('instructor_id', $instructorId)->open()->exists()) {
                    return null;
                }

                $cutoffId = (int) DB::table('ledger_entries')
                    ->where('instructor_id', $instructorId)
                    ->max('id');

                return Payout::create([
                    'instructor_id' => $instructorId,
                    'amount_minor' => $outstanding,
                    'currency' => config('revenue.currency'),
                    'status' => PayoutStatus::Pending,

                    // Generated once and stored, so every retry of THIS payout
                    // sends the same key and the provider refuses to pay twice.
                    'provider_idempotency_key' => 'po_'.Str::lower((string) Str::ulid()),

                    'ledger_cutoff_id' => $cutoffId,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Another worker opened a payout for this instructor first. Declining
            // is the correct outcome, and the database — not this code — decided it.
            return null;
        }
    }
}
