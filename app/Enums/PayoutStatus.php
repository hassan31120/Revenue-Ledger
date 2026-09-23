<?php

declare(strict_types=1);

namespace App\Enums;

enum PayoutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Failed], true);
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Processing, self::Unknown, self::Failed],

            self::Processing => [self::Paid, self::Failed, self::Unknown],

            self::Unknown => [self::Paid, self::Failed, self::Unknown],

            self::Paid => [],

            self::Failed => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Unknown => 'Unknown — needs reconciliation',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Processing => 'info',
            self::Paid => 'success',
            self::Failed => 'danger',
            self::Unknown => 'warning',
        };
    }
}
