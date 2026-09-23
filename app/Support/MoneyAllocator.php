<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class MoneyAllocator
{
    private const BASIS_POINTS = 10_000;

    public static function split(int $grossMinor, int $platformFeeBps, array $weights): RevenueSplit
    {
        if ($grossMinor < 0) {
            throw new InvalidArgumentException("Gross amount must not be negative, got {$grossMinor}.");
        }

        if ($platformFeeBps < 0 || $platformFeeBps > self::BASIS_POINTS) {
            throw new InvalidArgumentException("Platform fee must be 0-10000 basis points, got {$platformFeeBps}.");
        }

        $platformMinor = intdiv($grossMinor * $platformFeeBps, self::BASIS_POINTS);

        $poolMinor = $grossMinor - $platformMinor;

        return new RevenueSplit(
            grossMinor: $grossMinor,
            platformMinor: $platformMinor,
            instructorMinor: self::distribute($poolMinor, $weights),
        );
    }

    public static function distribute(int $amountMinor, array $weights): array
    {
        if ($amountMinor < 0) {
            throw new InvalidArgumentException(
                "distribute() takes a non-negative amount, got {$amountMinor}. Negate the result instead."
            );
        }

        if ($weights === []) {
            throw new InvalidArgumentException('Cannot distribute money across an empty set of participants.');
        }

        foreach ($weights as $id => $weight) {
            if ($weight <= 0) {
                throw new InvalidArgumentException("Weight for participant {$id} must be positive, got {$weight}.");
            }
        }

        $totalWeight = array_sum($weights);

        $shares = [];
        $remainders = [];

        foreach ($weights as $id => $weight) {
            $product = $amountMinor * $weight;

            $shares[$id] = intdiv($product, $totalWeight);
            $remainders[$id] = $product % $totalWeight;
        }

        $leftover = $amountMinor - array_sum($shares);

        if ($leftover > 0) {
            $order = array_keys($weights);

            usort($order, fn (int $a, int $b) => [$remainders[$b], $a] <=> [$remainders[$a], $b]);

            foreach (array_slice($order, 0, $leftover) as $id) {
                $shares[$id]++;
            }
        }

        return $shares;
    }
}
