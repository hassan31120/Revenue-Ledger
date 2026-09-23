<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public const MINOR_PER_MAJOR = 100;

    public static function format(int $minorUnits, ?string $currency = null): string
    {
        $currency ??= config('revenue.currency');

        $sign = $minorUnits < 0 ? '-' : '';
        $absolute = abs($minorUnits);

        return sprintf(
            '%s%s %d.%02d',
            $sign,
            $currency,
            intdiv($absolute, self::MINOR_PER_MAJOR),
            $absolute % self::MINOR_PER_MAJOR,
        );
    }

    public static function fromMajorString(string $amount): int
    {
        $amount = trim($amount);

        if (! preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw new InvalidArgumentException("Cannot parse '{$amount}' as a money amount.");
        }

        $minor = ((int) $matches[2]) * self::MINOR_PER_MAJOR
            + (int) str_pad($matches[3] ?? '0', 2, '0');

        return $matches[1] === '-' ? -$minor : $minor;
    }
}
