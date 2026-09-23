<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ReconcilePayout;
use App\Models\Payout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ReconcilePayoutJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public array $backoff = [30, 60, 300, 900, 1800];

    public function __construct(public readonly int $payoutId)
    {
        $this->onQueue(config('revenue.payouts.queue'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("reconcile:payout:{$this->payoutId}"))->dontRelease()];
    }

    public function handle(ReconcilePayout $reconcile): void
    {
        $payout = Payout::find($this->payoutId);

        if ($payout === null || $payout->status->isTerminal()) {
            return;
        }

        $reconcile->handle($payout);
    }

    public function failed(?\Throwable $exception): void {}
}
