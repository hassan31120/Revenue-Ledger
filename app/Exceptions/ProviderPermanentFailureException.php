<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ProviderPermanentFailureException extends RuntimeException
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $reason = 'declined',
    ) {
        parent::__construct("Payment provider permanently rejected '{$idempotencyKey}': {$reason}.");
    }
}
