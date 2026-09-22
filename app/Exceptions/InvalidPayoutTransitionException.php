<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PayoutStatus;
use RuntimeException;

final class InvalidPayoutTransitionException extends RuntimeException
{
    public static function between(int $payoutId, PayoutStatus $from, PayoutStatus $to): self
    {
        return new self(
            "Payout #{$payoutId} cannot move from {$from->value} to {$to->value}."
            .($from === PayoutStatus::Paid
                ? ' A paid payout is final and must never become payable again.'
                : '')
        );
    }
}
