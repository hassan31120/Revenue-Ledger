<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(DatabaseMigrations::class)
    ->in('Concurrency');

expect()->extend('toExactlyAccountFor', function (int $grossMinor) {
    $total = array_sum($this->value);

    expect($total)->toBe(
        $grossMinor,
        "Money was not conserved: parts total {$total} minor units, expected exactly {$grossMinor}."
    );

    return $this;
});
