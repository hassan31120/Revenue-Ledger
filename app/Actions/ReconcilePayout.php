<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PayoutStatus;
use App\Enums\ProviderPaymentState;
use App\Models\Payout;
use App\Services\Payments\PaymentProvider;
use App\Services\PayoutRecorder;

/**
 * Resolves a payout whose outcome is unknown, by asking the provider.
 *
 * This is the only thing allowed to settle the question after a timeout. It never
 * re-sends the payment — it only asks what happened, using the same idempotency
 * key the original attempt used.
 *
 * Three outcomes:
 *
 *   settled    the money did move → mark paid, debit the ledger exactly once
 *   not found  the money did not move → mark failed, but only after a grace
 *              period, because a provider may not have registered the payment yet
 *   pending    the provider is still deciding → stay unknown, ask again later
 *
 * A payout that cannot be resolved stays unknown indefinitely and surfaces in the
 * admin screen for a human. That is the correct outcome: an unresolved payment is
 * a real operational state, and pretending otherwise is how money goes missing.
 */
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

        // A payout stuck in "processing" belongs to a worker that died. Move it to
        // unknown first, so the state machine describes reality before we ask.
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

        // The money moved after all. The debit lands exactly once, guaranteed by
        // the unique ledger idempotency key rather than by this code being careful.
        $this->recorder->markPaid($payout, $reference ?? $payout->provider_idempotency_key);

        return $payout->fresh();
    }

    private function resolveAsFailed(Payout $payout, string $reason): Payout
    {
        $this->recorder->recordAttempt($payout, 'status_lookup', 'permanent_failure', null, $reason);
        $this->recorder->markFailed($payout, $reason);

        return $payout->fresh();
    }

    /**
     * The provider has no record of the payment.
     *
     * Almost always this means it never arrived — but "almost always" is not good
     * enough for money. A grace period guards against concluding failure while a
     * payment is still propagating through the provider's own systems.
     */
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

    /**
     * Still ambiguous. Back off and ask again — the instructor stays frozen
     * meanwhile, which is what prevents a second payment for the same earnings.
     */
    private function stayUnknown(Payout $payout, string $reason): Payout
    {
        $this->recorder->recordAttempt($payout, 'status_lookup', 'pending', null, $reason);

        // Widening backoff, capped, based on how many times we have already asked.
        $delay = min(3600, (int) config('revenue.payouts.reconcile_delay_seconds') * max(1, $payout->attempts));

        $this->recorder->markUnknown($payout, $reason, $delay);

        return $payout->fresh();
    }
}
