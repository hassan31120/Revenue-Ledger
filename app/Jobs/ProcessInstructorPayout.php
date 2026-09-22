<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ClaimInstructorPayout;
use App\Actions\SettlePayout;
use App\Enums\PayoutStatus;
use App\Models\Payout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pays one instructor.
 *
 * Safe to run twice, to run concurrently with itself, and to be retried after a
 * crash — none of which depends on this class. The guarantees live in the
 * database: the unique index on the payouts generated column, the conditional
 * status updates, and the unique idempotency key on the ledger debit.
 */
class ProcessInstructorPayout implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Widening gaps between retries. A provider that just timed out is unlikely
     * to be healthy a second later.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $instructorId,
        public readonly ?int $minimumMinor = null,
    ) {
        $this->onQueue(config('revenue.payouts.queue'));
    }

    /**
     * A cache lock keyed per instructor. This is an OPTIMISATION — it avoids
     * pointless contention — and explicitly not the correctness guarantee: cache
     * locks expire, and a lock held by a dead worker eventually releases. Unique
     * indexes do not expire.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("payout:instructor:{$this->instructorId}"))->dontRelease()];
    }

    public function handle(ClaimInstructorPayout $claim, SettlePayout $settle): void
    {
        $payout = $claim->handle($this->instructorId, $this->minimumMinor);

        if ($payout === null) {
            // Nothing payable, or another worker already holds this instructor's
            // open payout. Both are ordinary outcomes, not errors.
            return;
        }

        $settle->handle($payout);
    }

    /**
     * The job died — an exception escaped, the worker was killed, or the process
     * ran out of memory mid-provider-call.
     *
     * This handler must NEVER mark a payout failed. By definition a job that died
     * mid-flight does not know what the provider did, and "failed" is a claim that
     * no money moved. It moves the payout to unknown instead and lets
     * reconciliation establish the truth from the provider itself.
     */
    public function failed(?Throwable $exception): void
    {
        $payout = Payout::query()
            ->where('instructor_id', $this->instructorId)
            ->whereIn('status', [PayoutStatus::Pending->value, PayoutStatus::Processing->value])
            ->orderByDesc('id')
            ->first();

        if ($payout === null) {
            return;
        }

        Payout::query()
            ->whereKey($payout->id)
            ->whereIn('status', [PayoutStatus::Pending->value, PayoutStatus::Processing->value])
            ->update([
                'status' => PayoutStatus::Unknown->value,
                'last_error' => 'Job failed: '.($exception?->getMessage() ?? 'unknown error'),
                'reconcile_after' => now()->addSeconds((int) config('revenue.payouts.reconcile_delay_seconds')),
                'updated_at' => now(),
            ]);

        DB::table('payout_attempts')->insert([
            'payout_id' => $payout->id,
            'attempt_no' => $payout->attempts,
            'kind' => 'send',
            'outcome' => 'timeout',
            'idempotency_key_sent' => $payout->provider_idempotency_key,
            'detail' => 'Worker died before an outcome was recorded; treated as unknown.',
            'created_at' => now(),
        ]);
    }
}
