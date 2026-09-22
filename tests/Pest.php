<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against a real MySQL database (see phpunit.xml) because the
| financial invariants in this system are enforced by the database, not by the
| application: CHECK constraints, a STORED generated column acting as a partial
| unique index, ledger immutability triggers, and SELECT ... FOR UPDATE locking.
|
| Unit tests deliberately do NOT touch the database. The money allocator is pure,
| and keeping it that way is what makes the rounding rules cheap to reason about.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Concurrency tests act as two independent workers, so they must COMMIT their
// setup data — a second connection cannot see another session's open
// transaction. They migrate per test instead of wrapping it in one.
pest()->extend(TestCase::class)
    ->use(DatabaseMigrations::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| Domain expectations are added here as the financial core lands, so that tests
| read as statements about money rather than as assertions about arrays.
|
*/

/**
 * Assert that a set of allocated minor-unit amounts, plus the platform's share,
 * accounts for exactly the gross amount — no minor unit created, none lost.
 */
expect()->extend('toExactlyAccountFor', function (int $grossMinor) {
    $total = array_sum($this->value);

    expect($total)->toBe(
        $grossMinor,
        "Money was not conserved: parts total {$total} minor units, expected exactly {$grossMinor}."
    );

    return $this;
});
