<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | Every amount in this system is stored as an integer number of MINOR units
    | of this currency. EGP 100.50 is stored as 10050. There is no floating point
    | anywhere in the money path, and no decimal column.
    |
    | The system is single-currency by design. Amounts carry their currency code
    | so that a future multi-currency change is a migration rather than a rewrite,
    | but mixing currencies inside one allocation is treated as a bug and throws.
    |
    */

    'currency' => env('REVENUE_CURRENCY', 'EGP'),

    /*
    |--------------------------------------------------------------------------
    | Platform commission
    |--------------------------------------------------------------------------
    |
    | Expressed in basis points: 3000 = 30.00%. Basis points keep the fee an
    | integer, so the commission calculation never needs a float.
    |
    | This value is SNAPSHOTTED onto each subscription at purchase time. Changing
    | it here affects future subscriptions only — it can never retroactively
    | rewrite money that has already been allocated.
    |
    */

    'platform_fee_bps' => (int) env('REVENUE_PLATFORM_FEE_BPS', 3000),

    /*
    |--------------------------------------------------------------------------
    | Payouts
    |--------------------------------------------------------------------------
    */

    'payouts' => [

        // Minimum balance, in minor units, before an instructor is paid at all.
        // Stops the system generating provider fees for trivial amounts.
        'minimum_minor' => (int) env('PAYOUTS_MINIMUM_MINOR', 10000),

        // How long to wait before reconciling a payout whose result is unknown.
        'reconcile_delay_seconds' => (int) env('PAYOUTS_RECONCILE_DELAY_SECONDS', 60),

        // How long the provider is given to register a payment before a
        // "no record found" answer is accepted as proof that no money moved.
        // Without this, a payment still propagating inside the provider could
        // be written off as failed and then paid a second time.
        'not_found_grace_seconds' => (int) env('PAYOUTS_NOT_FOUND_GRACE_SECONDS', 300),

        // A payout still "processing" after this long is assumed to belong to a
        // crashed worker, and is swept into reconciliation.
        'stale_processing_minutes' => (int) env('PAYOUTS_STALE_PROCESSING_MINUTES', 15),

        // Queue that payout jobs are dispatched to.
        'queue' => env('PAYOUTS_QUEUE', 'payouts'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mock payment provider
    |--------------------------------------------------------------------------
    |
    | Drives the behaviour of the fake external provider used in place of a real
    | payment rail. Supported modes:
    |
    |   success                 provider moves the money and confirms it
    |   permanent_failure       provider does not move the money, and says so
    |   timeout_after_success   provider MOVES THE MONEY, then the call times out
    |   timeout_before_success  call times out before any money moves
    |
    | The third mode is the one that matters: the application must not conclude
    | that a timeout means failure.
    |
    */

    'provider' => [
        'mock_mode' => env('PAYOUTS_MOCK_MODE', 'success'),
    ],

];
