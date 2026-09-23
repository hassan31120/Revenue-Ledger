<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClaimInstructorPayout
{
    public function handle(int $instructorId, ?int $minimumMinor = null): ?Payout
    {
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

                if ($outstanding < $minimum) {
                    return null;
                }

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

                    'provider_idempotency_key' => 'po_'.Str::lower((string) Str::ulid()),

                    'ledger_cutoff_id' => $cutoffId,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
