<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

final readonly class RevenueSplit
{
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

    public function instructorPoolMinor(): int
    {
        return $this->grossMinor - $this->platformMinor;
    }

    public function forInstructor(int $instructorId): int
    {
        return $this->instructorMinor[$instructorId] ?? 0;
    }
}
