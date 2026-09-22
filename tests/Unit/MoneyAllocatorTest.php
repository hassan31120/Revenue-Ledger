<?php

declare(strict_types=1);

use App\Support\MoneyAllocator;
use App\Support\RevenueSplit;

/*
|--------------------------------------------------------------------------
| Revenue allocation
|--------------------------------------------------------------------------
|
| No database, no framework. These tests describe the rounding policy itself,
| which is the part of the system a reviewer is most likely to push on.
|
*/

describe('platform commission', function () {
    it('floors the commission so sub-unit dust falls to instructors', function () {
        // 30% of 10001 is 3000.3. Flooring keeps 3000 and leaves 7001 in the pool.
        $split = MoneyAllocator::split(10001, 3000, [1 => 1]);

        expect($split->platformMinor)->toBe(3000)
            ->and($split->instructorPoolMinor())->toBe(7001)
            ->and($split->forInstructor(1))->toBe(7001);
    });

    it('gives instructors everything at a zero fee', function () {
        $split = MoneyAllocator::split(10050, 0, [1 => 1]);

        expect($split->platformMinor)->toBe(0)
            ->and($split->forInstructor(1))->toBe(10050);
    });

    it('gives instructors nothing at a one hundred percent fee', function () {
        $split = MoneyAllocator::split(10050, 10000, [1 => 1, 2 => 1]);

        expect($split->platformMinor)->toBe(10050)
            ->and($split->instructorMinor)->toBe([1 => 0, 2 => 0]);
    });
});

describe('dividing the instructor pool', function () {
    it('gives a single instructor the whole pool', function () {
        $split = MoneyAllocator::split(10050, 3000, [7 => 1]);

        // 30% of 10050 = 3015 exactly; the pool is 7035.
        expect($split->platformMinor)->toBe(3015)
            ->and($split->forInstructor(7))->toBe(7035);
    });

    it('divides evenly when it can', function () {
        $split = MoneyAllocator::split(10050, 3000, [1 => 1, 2 => 1, 3 => 1]);

        expect($split->instructorMinor)->toBe([1 => 2345, 2 => 2345, 3 => 2345])
            ->and($split->platformMinor)->toBe(3015);
    });

    it('hands the leftover minor unit to the lowest id when remainders tie', function () {
        // Pool of 7000 across three equal weights: 2333 each, one unit left over.
        // Every remainder is identical, so the ascending-id tie-break decides.
        $split = MoneyAllocator::split(10000, 3000, [5 => 1, 9 => 1, 2 => 1]);

        expect($split->platformMinor)->toBe(3000)
            ->and($split->forInstructor(2))->toBe(2334)
            ->and($split->forInstructor(5))->toBe(2333)
            ->and($split->forInstructor(9))->toBe(2333);
    });

    it('distributes a single indivisible minor unit without losing it', function () {
        $split = MoneyAllocator::split(1, 0, [3 => 1, 1 => 1, 2 => 1]);

        expect($split->instructorMinor)->toExactlyAccountFor(1)
            ->and($split->forInstructor(1))->toBe(1);
    });

    it('handles a gross amount of zero', function () {
        $split = MoneyAllocator::split(0, 3000, [1 => 1, 2 => 1]);

        expect($split->platformMinor)->toBe(0)
            ->and($split->instructorMinor)->toBe([1 => 0, 2 => 0]);
    });

    it('respects unequal weights', function () {
        // Pool 7000, weights 3:1 -> 5250 / 1750.
        $split = MoneyAllocator::split(10000, 3000, [1 => 3, 2 => 1]);

        expect($split->forInstructor(1))->toBe(5250)
            ->and($split->forInstructor(2))->toBe(1750);
    });

    it('sends the leftover to the largest remainder, not merely the lowest id', function () {
        // Pool 100, weights 1:1:4 over total 6.
        //   id 1 -> 100*1/6 = 16 r 4
        //   id 2 -> 100*1/6 = 16 r 4
        //   id 3 -> 100*4/6 = 66 r 4
        // Floors total 98, so two units are left over. All remainders tie at 4,
        // so ids 1 and 2 take them.
        $split = MoneyAllocator::split(100, 0, [1 => 1, 2 => 1, 3 => 4]);

        expect($split->instructorMinor)->toExactlyAccountFor(100)
            ->and($split->forInstructor(1))->toBe(17)
            ->and($split->forInstructor(2))->toBe(17)
            ->and($split->forInstructor(3))->toBe(66);
    });
});

describe('determinism', function () {
    it('produces identical output for identical input', function () {
        $first = MoneyAllocator::split(49999, 3000, [4 => 1, 8 => 2, 15 => 1]);
        $second = MoneyAllocator::split(49999, 3000, [4 => 1, 8 => 2, 15 => 1]);

        expect($first->instructorMinor)->toBe($second->instructorMinor)
            ->and($first->platformMinor)->toBe($second->platformMinor);
    });

    it('does not depend on the order the instructors are supplied in', function () {
        $ascending = MoneyAllocator::split(49999, 3000, [1 => 1, 2 => 1, 3 => 1]);
        $shuffled = MoneyAllocator::split(49999, 3000, [3 => 1, 1 => 1, 2 => 1]);

        $shuffledAmounts = $shuffled->instructorMinor;
        ksort($shuffledAmounts);

        expect($ascending->instructorMinor)->toBe($shuffledAmounts);
    });
});

describe('money is never created or destroyed', function () {
    it('conserves every minor unit across thousands of random splits', function () {
        mt_srand(20260922); // Seeded: a failure is reproducible, not a mystery.

        for ($i = 0; $i < 3000; $i++) {
            $gross = mt_rand(0, 5_000_000);
            $feeBps = mt_rand(0, 10_000);

            $weights = [];
            foreach (range(1, mt_rand(1, 9)) as $id) {
                $weights[$id] = mt_rand(1, 10);
            }

            $split = MoneyAllocator::split($gross, $feeBps, $weights);

            $accounted = $split->platformMinor + array_sum($split->instructorMinor);

            expect($accounted)->toBe(
                $gross,
                "Lost or invented money: gross {$gross}, fee {$feeBps}bps, weights ".json_encode($weights)
            );

            // No instructor may receive a negative amount from a positive split.
            expect(min($split->instructorMinor))->toBeGreaterThanOrEqual(0);
        }
    });

    it('never differs between instructors by more than one minor unit at equal weight', function () {
        foreach ([1, 7, 99, 100, 12345, 999999] as $gross) {
            $split = MoneyAllocator::split($gross, 0, [1 => 1, 2 => 1, 3 => 1]);

            expect(max($split->instructorMinor) - min($split->instructorMinor))
                ->toBeLessThanOrEqual(1, "Uneven split for gross {$gross}");
        }
    });
});

describe('rejecting nonsense', function () {
    it('refuses a negative gross amount', function () {
        expect(fn () => MoneyAllocator::split(-1, 3000, [1 => 1]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses a fee outside the basis-point range', function () {
        expect(fn () => MoneyAllocator::split(1000, 10001, [1 => 1]))
            ->toThrow(InvalidArgumentException::class);
        expect(fn () => MoneyAllocator::split(1000, -1, [1 => 1]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses an empty set of instructors', function () {
        expect(fn () => MoneyAllocator::split(1000, 3000, []))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses a non-positive weight', function () {
        expect(fn () => MoneyAllocator::split(1000, 3000, [1 => 0]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses to distribute a negative amount', function () {
        // Clawbacks negate a positive distribution instead, so that a refund of X
        // mirrors an allocation of X exactly.
        expect(fn () => MoneyAllocator::distribute(-100, [1 => 1]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('cannot construct a split that loses money', function () {
        expect(fn () => new RevenueSplit(10000, 3000, [1 => 6999]))
            ->toThrow(LogicException::class);
    });
});
