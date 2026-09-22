<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

/**
 * The result of splitting one subscription payment.
 *
 * The conservation invariant is enforced in the CONSTRUCTOR rather than by a
 * caller remembering to check it. A RevenueSplit that loses or invents a minor
 * unit cannot be constructed, so no code downstream has to trust the allocator —
 * it only has to hold one of these.
 */
final readonly class RevenueSplit
{
    /**
     * @param  array<int, int>  $instructorMinor  instructor id => allocated minor units
     */
    public function __construct(
        public int $grossMinor,
        public int $platformMinor,
        public array $instructorMinor,
    ) {
        $accounted = $platformMinor + array_sum($instructorMinor);

        if ($accounted !== $grossMinor) {
            throw new LogicException(sprintf(
                'Revenue split does not conserve money: platform %d + instructors %d = %d, but gross is %d.',
                $platformMinor,
                array_sum($instructorMinor),
                $accounted,
                $grossMinor,
            ));
        }
    }

    /**
     * Everything that belongs to instructors, before it is divided between them.
     */
    public function instructorPoolMinor(): int
    {
        return $this->grossMinor - $this->platformMinor;
    }

    public function forInstructor(int $instructorId): int
    {
        return $this->instructorMinor[$instructorId] ?? 0;
    }
}
