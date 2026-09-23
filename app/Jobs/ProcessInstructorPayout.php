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

class ProcessInstructorPayout implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $instructorId,
        public readonly ?int $minimumMinor = null,
    ) {
        $this->onQueue(config('revenue.payouts.queue'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("payout:instructor:{$this->instructorId}"))->dontRelease()];
    }

    public function handle(ClaimInstructorPayout $claim, SettlePayout $settle): void
    {
        $payout = $claim->handle($this->instructorId, $this->minimumMinor);

        if ($payout === null) {
            return;
        }

        $settle->handle($payout);
    }

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
