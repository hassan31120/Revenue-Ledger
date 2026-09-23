<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('revenue:allocate')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('payouts:process')
    ->dailyAt('02:00')
    ->withoutOverlapping();

Schedule::command('payouts:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('ledger:verify')
    ->dailyAt('03:00')
    ->withoutOverlapping();
