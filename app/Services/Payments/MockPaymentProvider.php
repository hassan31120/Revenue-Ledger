<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\ProviderPaymentState;
use App\Exceptions\ProviderPermanentFailureException;
use App\Exceptions\ProviderTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A stand-in for a real payment rail, with provider-side state.
 *
 * It is deliberately not a stub. It keeps its own record of every payment it has
 * accepted, keyed by the idempotency key, so that:
 *
 *   - re-sending a key returns the ORIGINAL payment instead of moving money twice;
 *   - after a timeout, getPaymentStatus() can tell the truth about whether the
 *     money moved — including the case where it did.
 *
 * Behaviour is chosen by config('revenue.provider.mock_mode'):
 *
 *   success                 money moves, caller is told
 *   permanent_failure       money does not move, caller is told
 *   timeout_after_success   MONEY MOVES, then the caller gets a timeout
 *   timeout_before_success  money does not move, and the caller gets a timeout
 *
 * The third mode is the whole point. From the caller's side it is indistinguishable
 * from the fourth — which is exactly why a timeout may never be treated as failure.
 */
final class MockPaymentProvider implements PaymentProvider
{
    private const TABLE = 'mock_provider_payments';

    public function sendPayout(
        string $idempotencyKey,
        int $amountMinor,
        string $currency,
        string $destinationAccount,
    ): PayoutResult {
        // Idempotency first, before any mode handling: a key the provider has
        // already accepted returns the original payment whatever the mode is.
        // This is what makes retrying a timed-out payout safe.
        if ($existing = $this->find($idempotencyKey)) {
            return new PayoutResult(
                reference: $existing->reference,
                state: ProviderPaymentState::Settled,
                wasAlreadyProcessed: true,
            );
        }

        $mode = (string) config('revenue.provider.mock_mode', 'success');

        return match ($mode) {
            'success' => $this->settle($idempotencyKey, $amountMinor, $currency, $destinationAccount),

            'permanent_failure' => throw new ProviderPermanentFailureException(
                $idempotencyKey,
                'insufficient platform balance',
            ),

            // The money moves, and THEN the connection drops.
            'timeout_after_success' => $this->settleThenTimeout(
                $idempotencyKey, $amountMinor, $currency, $destinationAccount
            ),

            'timeout_before_success' => throw new ProviderTimeoutException($idempotencyKey),

            default => throw new \InvalidArgumentException("Unknown mock provider mode '{$mode}'."),
        };
    }

    public function getPaymentStatus(string $referenceOrIdempotencyKey): PaymentStatus
    {
        $payment = $this->find($referenceOrIdempotencyKey)
            ?? DB::table(self::TABLE)->where('reference', $referenceOrIdempotencyKey)->first();

        if ($payment === null) {
            // The provider has never heard of it, so no money moved. This is the
            // only evidence that justifies calling a payout failed after a timeout.
            return new PaymentStatus(ProviderPaymentState::NotFound);
        }

        return new PaymentStatus(
            state: ProviderPaymentState::from($payment->status),
            reference: $payment->reference,
            amountMinor: (int) $payment->amount_minor,
        );
    }

    private function settle(string $key, int $amountMinor, string $currency, string $account): PayoutResult
    {
        $reference = $this->record($key, $amountMinor, $currency, $account);

        return new PayoutResult($reference, ProviderPaymentState::Settled);
    }

    private function settleThenTimeout(string $key, int $amountMinor, string $currency, string $account): never
    {
        $this->record($key, $amountMinor, $currency, $account);

        // The caller never learns the reference. All it gets is silence.
        throw new ProviderTimeoutException($key);
    }

    private function record(string $key, int $amountMinor, string $currency, string $account): string
    {
        $reference = 'mockpay_'.Str::lower(Str::random(24));

        DB::table(self::TABLE)->insert([
            'idempotency_key' => $key,
            'reference' => $reference,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'destination_account' => $account,
            'status' => ProviderPaymentState::Settled->value,
            'created_at' => now(),
        ]);

        return $reference;
    }

    private function find(string $idempotencyKey): ?object
    {
        return DB::table(self::TABLE)->where('idempotency_key', $idempotencyKey)->first();
    }
}
