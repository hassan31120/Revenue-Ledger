<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three subscription terms. Every term is paid in full, upfront.
 */
enum PlanCode: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annual = 'annual';

    public function durationMonths(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Annual => 12,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Quarterly => '3-Month',
            self::Annual => 'Annual',
        };
    }
}
