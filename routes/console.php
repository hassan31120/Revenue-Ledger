<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| Every one of these is safe to run twice, to overlap with itself, and to be
| interrupted halfway through. That is a property of the commands themselves —
| enforced by database constraints — not of the schedule, so a missed run or a
| duplicated one is never a financial problem.
|
*/

// Allocate revenue for any subscription that has not been allocated yet.
// In production this happens when a payment is confirmed; this is the safety net.
Schedule::command('revenue:allocate')
    ->hourly()
    ->withoutOverlapping();

// Pay instructors whose balance is above the minimum.
Schedule::command('payouts:process')
    ->dailyAt('02:00')
    ->withoutOverlapping();

// Resolve payouts whose outcome is unknown, and sweep up any left mid-flight by
// a worker that died. Frequent, because every unresolved payout is an instructor
// who cannot be paid until it is settled.
Schedule::command('payouts:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Prove the projection still agrees with the ledger and that money is conserved.
// Exits non-zero on any drift, so failure output is worth alerting on.
Schedule::command('ledger:verify')
    ->dailyAt('03:00')
    ->withoutOverlapping();
