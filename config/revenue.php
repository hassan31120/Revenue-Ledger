<?php

declare(strict_types=1);

return [

    'currency' => env('REVENUE_CURRENCY', 'EGP'),

    'platform_fee_bps' => (int) env('REVENUE_PLATFORM_FEE_BPS', 3000),

    'payouts' => [

        'minimum_minor' => (int) env('PAYOUTS_MINIMUM_MINOR', 10000),

        'reconcile_delay_seconds' => (int) env('PAYOUTS_RECONCILE_DELAY_SECONDS', 60),

        'not_found_grace_seconds' => (int) env('PAYOUTS_NOT_FOUND_GRACE_SECONDS', 300),

        'stale_processing_minutes' => (int) env('PAYOUTS_STALE_PROCESSING_MINUTES', 15),

        'queue' => env('PAYOUTS_QUEUE', 'payouts'),
    ],

    'provider' => [
        'mock_mode' => env('PAYOUTS_MOCK_MODE', 'success'),

        'random_weights' => [
            'success' => 70,
            'permanent_failure' => 10,
            'timeout_after_success' => 10,
            'timeout_before_success' => 10,
        ],
    ],
];
