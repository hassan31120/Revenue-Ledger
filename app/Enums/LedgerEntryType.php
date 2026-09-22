<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sign convention: a credit to the account is positive, a debit negative.
 */
enum LedgerEntryType: string
{
    /** + An instructor's share of a subscription payment. */
    case Earning = 'earning';

    /** + The platform's commission on a subscription payment. */
    case PlatformFee = 'platform_fee';

    /** - Money sent to an instructor and CONFIRMED by the provider. */
    case Payout = 'payout';

    /** -/+ Clawback (or restoration) when a subscription is refunded. */
    case RefundAdjustment = 'refund_adjustment';

    /** -/+ A deliberate correction, recorded rather than applied in place. */
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
