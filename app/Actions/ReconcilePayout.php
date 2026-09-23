<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PayoutStatus;
use App\Enums\ProviderPaymentState;
use App\Models\Payout;
use App\Services\Payments\PaymentProvider;
use App\Services\PayoutRecorder;

final class ReconcilePayout
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly PayoutRecorder $recorder,
    ) {}

    public function handle(Payout $payout): Payout
    {
        if ($payout->status->isTerminal()) {
            return $payout;
        }

        if ($payout->status === PayoutStatus::Processing) {
            $this->recorder->markUnknown($payout, 'Worker did not record an outcome; reconciling.', 0);
        }

        $status = $this->provider->getPaymentStatus($payout->provider_idempotency_key);

        return match ($status->state) {
            ProviderPaymentState::Settled => $this->resolveAsPaid($payout, $status->reference),
            ProviderPaymentState::Failed => $this->resolveAsFailed($payout, 'Provider reports the payment failed.'),
            ProviderPaymentState::NotFound => $this->resolveNotFound($payout),
            ProviderPaymentState::Pending => $this->stayUnknown($payout, 'Provider still reports the payment as pending.'),
        };
    }

    private function resolveAsPaid(Payout $payout, ?string $reference): Payout
    {
        $this->recorder->recordAttempt($payout, 'status_lookup', 'success', $reference);

        $this->recorder->markPaid($payout, $reference ?? $payout->provider_idempotency_key);

        return $payout->fresh();
    }

    private function resolveAsFailed(Payout $payout, string $reason): Payout
    {
        $this->recorder->recordAttempt($payout, 'status_lookup', 'permanent_failure', null, $reason);
        $this->recorder->markFailed($payout, $reason);

        return $payout->fresh();
    }

    private function resolveNotFound(Payout $payout): Payout
    {
        $graceSeconds = (int) config('revenue.payouts.not_found_grace_seconds');

        if ($payout->created_at->addSeconds($graceSeconds)->isFuture()) {
            return $this->stayUnknown(
                $payout,
                'Provider has no record yet; still inside the grace period.'
            );
        }

        $this->recorder->recordAttempt($payout, 'status_lookup', 'not_found', null, 'No record at the provider after the grace period.');
        $this->recorder->markFailed($payout, 'Provider has no record of this payment; no money moved.');

        return $payout->fresh();
    }

    private function stayUnknown(Payout $payout, string $reason): Payout
    {
        $this->recorder->recordAttempt($payout, 'status_lookup', 'pending', null, $reason);

        $delay = min(3600, (int) config('revenue.payouts.reconcile_delay_seconds') * max(1, $payout->attempts));

        $this->recorder->markUnknown($payout, $reason, $delay);

        return $payout->fresh();
    }
}
