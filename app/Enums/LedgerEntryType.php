<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerEntryType: string
{
    case Earning = 'earning';

    case PlatformFee = 'platform_fee';

    case Payout = 'payout';

    case RefundAdjustment = 'refund_adjustment';

    case ManualAdjustment = 'manual_adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Earning => 'Earning',
            self::PlatformFee => 'Platform fee',
            self::Payout => 'Payout',
            self::RefundAdjustment => 'Refund adjustment',
            self::ManualAdjustment => 'Manual adjustment',
        };
    }
}
