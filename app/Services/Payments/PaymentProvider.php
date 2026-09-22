<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\ProviderPermanentFailureException;
use App\Exceptions\ProviderTimeoutException;

/**
 * The boundary between this system and the outside world.
 *
 * This is the only interface in the project. It earns its place twice over: it is
 * a genuine external seam, and it is what lets every failure mode be exercised in
 * tests without a network.
 */
interface PaymentProvider
{
    /**
     * Send money to an instructor.
     *
     * $idempotencyKey is the contract that makes retries safe. Calling this again
     * with a key the provider has already seen must return the ORIGINAL payment
     * rather than moving money a second time.
     *
     * @throws ProviderTimeoutException the outcome is unknown; money may have moved
     * @throws ProviderPermanentFailureException definitive rejection; no money moved
     */
    public function sendPayout(
        string $idempotencyKey,
        int $amountMinor,
        string $currency,
        string $destinationAccount,
    ): PayoutResult;

    /**
     * Ask the provider what actually happened.
     *
     * Accepts either the idempotency key we sent or a reference the provider
     * returned — after a timeout we may hold only the former.
     */
    public function getPaymentStatus(string $referenceOrIdempotencyKey): PaymentStatus;
}
