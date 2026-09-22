<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Divides money. Pure, static, and deliberately free of Eloquent, config and
 * clocks — the rounding rules are the part of this system most likely to be
 * challenged in review, so they are testable without a database.
 *
 * ── Rules ────────────────────────────────────────────────────────────────────
 *
 * 1. Money is integer MINOR units throughout. There is no float, no division
 *    operator, and no round() anywhere in this class: only intdiv(), %, + and -.
 *
 * 2. The platform commission FLOORS. Any sub-unit dust therefore falls into the
 *    instructor pool rather than out of it. This is a policy choice, and it is
 *    the conservative direction: the platform never rounds in its own favour.
 *
 * 3. The instructor pool is divided by the Largest Remainder (Hamilton) method:
 *    everyone takes their floored share, and the leftover minor units go one
 *    each to the largest fractional remainders, ties broken by ascending
 *    instructor id.
 *
 * 4. Because that tie-break is total, the result is DETERMINISTIC. Two servers
 *    given the same inputs produce byte-identical output, which is what makes a
 *    re-run of allocation safe to compare against what was already stored.
 *
 * 5. Nothing is created or lost: platform + instructors == gross, exactly. This
 *    is enforced structurally by RevenueSplit.
 *
 * Practical range: amounts are bounded by PHP's 64-bit integers. The largest
 * intermediate value is amountMinor × weight, which for any realistic EGP amount
 * and weight is many orders of magnitude below PHP_INT_MAX.
 */
final class MoneyAllocator
{
    private const BASIS_POINTS = 10_000;

    /**
     * Split a gross subscription payment between the platform and the instructors.
     *
     * @param  array<int, int>  $weights  instructor id => positive integer weight
     */
    public static function split(int $grossMinor, int $platformFeeBps, array $weights): RevenueSplit
    {
        if ($grossMinor < 0) {
            throw new InvalidArgumentException("Gross amount must not be negative, got {$grossMinor}.");
        }

        if ($platformFeeBps < 0 || $platformFeeBps > self::BASIS_POINTS) {
            throw new InvalidArgumentException("Platform fee must be 0-10000 basis points, got {$platformFeeBps}.");
        }

        // Floor the commission, so the remainder benefits instructors.
        $platformMinor = intdiv($grossMinor * $platformFeeBps, self::BASIS_POINTS);

        // Exact by construction — the pool absorbs whatever the fee left behind.
        $poolMinor = $grossMinor - $platformMinor;

        return new RevenueSplit(
            grossMinor: $grossMinor,
            platformMinor: $platformMinor,
            instructorMinor: self::distribute($poolMinor, $weights),
        );
    }

    /**
     * Divide an amount across weighted participants, losing nothing.
     *
     * Only non-negative amounts are accepted. Negative distribution (a refund
     * clawback) is expressed by distributing the positive amount and negating the
     * result, so that "largest remainder" keeps one unambiguous meaning and a
     * refund of X always mirrors an allocation of X exactly.
     *
     * @param  array<int, int>  $weights  participant id => positive integer weight
     * @return array<int, int> participant id => allocated minor units
     */
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

        // Whatever the floors left on the table. Strictly less than the number of
        // participants, so at most one extra minor unit each.
        $leftover = $amountMinor - array_sum($shares);

        if ($leftover > 0) {
            $order = array_keys($weights);

            // Largest remainder first; ties broken by ascending id so the ordering
            // is total and the outcome cannot depend on array iteration order.
            usort($order, fn (int $a, int $b) => [$remainders[$b], $a] <=> [$remainders[$a], $b]);

            foreach (array_slice($order, 0, $leftover) as $id) {
                $shares[$id]++;
            }
        }

        return $shares;
    }
}
