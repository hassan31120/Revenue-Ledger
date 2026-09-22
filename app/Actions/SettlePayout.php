<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PayoutStatus;
use App\Exceptions\ProviderPermanentFailureException;
use App\Exceptions\ProviderTimeoutException;
use App\Models\Payout;
use App\Services\Payments\PaymentProvider;
use App\Services\PayoutRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Sends a pending payout to the provider and records what came back.
 *
 * The shape of this method is the whole design:
 *
 *   1. Claim the payout with a CONDITIONAL update. Exactly one worker wins.
 *   2. Call the provider OUTSIDE any transaction.
 *   3. Record the outcome; only on confirmed success is the ledger debited.
 *
 * Step 2 is deliberate. Holding an InnoDB row lock across a network call to a
 * payment provider is how a payout system becomes an outage: one slow provider
 * and every worker piles up behind the lock.
 */
final class SettlePayout
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly PayoutRecorder $recorder,
    ) {}

    public function handle(Payout $payout): Payout
    {
        if ($payout->status->isTerminal()) {
            // Already resolved. A duplicate job for a settled payout is a no-op,
            // not an error — which is what makes at-least-once delivery safe.
            return $payout;
        }

        if (! $this->claim($payout)) {
            // Another worker holds it, or it is no longer pending. An unknown
            // payout is reconciliation's job, never this one's: re-sending it
            // would risk a second payment for the same earnings.
            return $payout->fresh();
        }

        $account = $payout->instructor()->value('payout_account_ref');

        try {
            $result = $this->provider->sendPayout(
                $payout->provider_idempotency_key,
                $payout->amount_minor,
                $payout->currency,
                $account,
            );

            $this->recorder->recordAttempt($payout, 'send', 'success', $result->reference);
            $this->recorder->markPaid($payout, $result->reference);
        } catch (ProviderTimeoutException $e) {
            // THE CASE THAT MATTERS.
            //
            // We do not know whether the money moved. Calling it a failure risks
            // paying twice; calling it a success risks reporting an instructor as
            // paid when they were not. So we assert neither and leave the payout
            // explicitly unresolved for reconciliation to settle.
            $this->recorder->recordAttempt($payout, 'send', 'timeout', null, $e->getMessage());
            $this->recorder->markUnknown($payout, $e->getMessage());
        } catch (ProviderPermanentFailureException $e) {
            // The provider answered, and the answer was definitive: no money moved.
            // Safe to fail, which returns the balance to payable immediately.
            $this->recorder->recordAttempt($payout, 'send', 'permanent_failure', null, $e->getMessage());
            $this->recorder->markFailed($payout, $e->getMessage());
        }

        return $payout->fresh();
    }

    /**
     * Take ownership of a pending payout.
     *
     * A conditional UPDATE, not a read-then-write: the loser of a race sees zero
     * affected rows and stops. No application-level check can do this safely,
     * because two workers can both read "pending" before either writes.
     */
    private function claim(Payout $payout): bool
    {
        $claimed = Payout::query()
            ->whereKey($payout->id)
            ->where('status', PayoutStatus::Pending->value)
            ->update([
                'status' => PayoutStatus::Processing->value,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed === 1) {
            $payout->status = PayoutStatus::Processing;
            $payout->attempts++;
        }

        return $claimed === 1;
    }
}
