<?php

declare(strict_types=1);

namespace App\Enums;

enum SubscriptionStatus: string
{
    /** Paid and running. Revenue has been recognised in full. */
    case Active = 'active';

    /** Some of the term's value has been refunded; the rest stands. */
    case PartiallyRefunded = 'partially_refunded';

    /** The entire refundable value has been returned to the student. */
    case Refunded = 'refunded';

    /** The term ran to completion. */
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::PartiallyRefunded => 'Partially refunded',
            self::Refunded => 'Refunded',
            self::Expired => 'Expired',
        };
    }
}
