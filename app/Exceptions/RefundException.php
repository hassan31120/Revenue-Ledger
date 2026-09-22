<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class RefundException extends RuntimeException
{
    public static function exceedsGross(int $subscriptionId, int $cumulativeMinor, int $grossMinor): self
    {
        return new self(
            "Refunding {$cumulativeMinor} minor units in total for subscription #{$subscriptionId} would exceed ".
            "what was actually paid ({$grossMinor}). A refund can never return more money than came in."
        );
    }

    public static function notAllocated(int $subscriptionId): self
    {
        return new self(
            "Subscription #{$subscriptionId} has no revenue allocation, so there is nothing to claw back. ".
            'Allocate it before refunding.'
        );
    }

    public static function notPositive(int $amountMinor): self
    {
        return new self("A refund must be for a positive amount, got {$amountMinor}.");
    }
}
