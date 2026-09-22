<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ReconcilePayout;
use App\Models\Payout;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Asks the provider what happened to one unresolved payout.
 *
 * Unlike a payment attempt, reconciliation is a pure question — it moves no money
 * and is therefore safe to repeat freely. It never re-sends a payment.
 */
class ReconcilePayoutJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 300, 900, 1800];

    public function __construct(public readonly int $payoutId)
    {
        $this->onQueue(config('revenue.payouts.queue'));
    }

    /**
     * @return array<int, object>
     */
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

    /**
     * Reconciliation giving up does NOT resolve the payout.
     *
     * The payout stays unknown, the instructor stays frozen, and the next sweep
     * picks it up again. An unresolved payment is an operational fact to be
     * surfaced, not an exception to be swallowed.
     */
    public function failed(?\Throwable $exception): void
    {
        // Intentionally empty: leaving the payout unknown is the safe outcome.
    }
}
