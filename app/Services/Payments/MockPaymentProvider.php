<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\ProviderPaymentState;
use App\Exceptions\ProviderPermanentFailureException;
use App\Exceptions\ProviderTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MockPaymentProvider implements PaymentProvider
{
    private const TABLE = 'mock_provider_payments';

    public function sendPayout(
        string $idempotencyKey,
        int $amountMinor,
        string $currency,
        string $destinationAccount,
    ): PayoutResult {
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
