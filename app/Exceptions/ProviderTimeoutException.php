<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ProviderTimeoutException extends RuntimeException
{
    public function __construct(public readonly string $idempotencyKey, string $message = '')
    {
        parent::__construct(
            $message !== '' ? $message : "Timed out waiting for the payment provider on '{$idempotencyKey}'. The outcome is UNKNOWN."
        );
    }
}
