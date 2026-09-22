<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider answered, and the answer was no.
 *
 * Definitive: no money moved. Unlike a timeout, this is safe to treat as a
 * failure — the balance becomes payable again immediately.
 */
final class ProviderPermanentFailureException extends RuntimeException
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $reason = 'declined',
    ) {
        parent::__construct("Payment provider permanently rejected '{$idempotencyKey}': {$reason}.");
    }
}
