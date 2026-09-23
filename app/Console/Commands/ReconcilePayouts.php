<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReconcilePayout;
use App\Jobs\ReconcilePayoutJob;
use App\Models\Payout;
use Illuminate\Console\Command;

class ReconcilePayouts extends Command
{
    protected $signature = 'payouts:reconcile
                            {--payout= : Reconcile a single payout by id}
                            {--stale-minutes= : Treat processing payouts older than this as crashed}
                            {--force : Ignore the backoff schedule and reconcile every unresolved payout now}
                            {--sync : Reconcile immediately instead of queueing}';

    protected $description = 'Resolve payouts whose outcome is unknown by asking the payment provider';

    public function handle(): int
    {
        $staleMinutes = (int) ($this->option('stale-minutes') ?? config('revenue.payouts.stale_processing_minutes'));

        $payouts = $this->unresolvedPayouts($staleMinutes);

        if ($payouts->isEmpty()) {
            $this->info('Nothing to reconcile: no payout has an unresolved outcome.');

            return self::SUCCESS;
        }

        foreach ($payouts as $payout) {
            if ($this->option('sync')) {
                app(ReconcilePayout::class)->handle($payout);
            } else {
                ReconcilePayoutJob::dispatch($payout->id);
            }
        }

        $verb = $this->option('sync') ? 'Reconciled' : 'Queued reconciliation for';
        $this->info("{$verb} {$payouts->count()} payout(s).");

        $this->table(
            ['Payout', 'Instructor', 'Status', 'Attempts', 'Last error'],
            $payouts->map(fn (Payout $p) => [
                $p->id,
                $p->instructor_id,
                $p->fresh()->status->value,
                $p->attempts,
                str($p->last_error ?? '')->limit(48),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function unresolvedPayouts(int $staleMinutes)
    {
        if ($payoutId = $this->option('payout')) {
            return Payout::query()->whereKey((int) $payoutId)->get();
        }

        if ($this->option('force')) {
            return Payout::query()->open()->orderBy('id')->get();
        }

        return Payout::query()
            ->where(fn ($q) => $q->dueForReconciliation())
            ->orWhere(fn ($q) => $q->staleProcessing($staleMinutes))
            ->orderBy('id')
            ->get();
    }
}
