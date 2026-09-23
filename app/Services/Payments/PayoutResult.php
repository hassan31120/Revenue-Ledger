<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\ProviderPaymentState;

final readonly class PayoutResult
{
    public function __construct(
        public string $reference,
        public ProviderPaymentState $state,
        public bool $wasAlreadyProcessed = false,
    ) {}
}
