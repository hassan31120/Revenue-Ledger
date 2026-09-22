<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider did not answer in time.
 *
 * This is the most important exception in the system, and the rule around it is
 * absolute: a timeout is NOT a failure. The request may have been received and
 * the money may already have moved; we simply do not know. Anything that catches
 * this must move the payout to "unknown" and reconcile later — never mark it
 * failed, and never retry it under a fresh idempotency key.
 */
final class ProviderTimeoutException extends RuntimeException
{
    public function __construct(public readonly string $idempotencyKey, string $message = '')
    {
        parent::__construct(
            $message !== '' ? $message : "Timed out waiting for the payment provider on '{$idempotencyKey}'. The outcome is UNKNOWN."
        );
    }
}
