<?php

declare(strict_types=1);

namespace App\Services\Payments;

interface PaymentProvider
{
    public function sendPayout(
        string $idempotencyKey,
        int $amountMinor,
        string $currency,
        string $destinationAccount,
    ): PayoutResult;

    public function getPaymentStatus(string $referenceOrIdempotencyKey): PaymentStatus;
}
