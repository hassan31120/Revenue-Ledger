<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\ProviderPaymentState;

final readonly class PaymentStatus
{
    public function __construct(
        public ProviderPaymentState $state,
        public ?string $reference = null,
        public ?int $amountMinor = null,
    ) {}

    public function isSettled(): bool
    {
        return $this->state === ProviderPaymentState::Settled;
    }
}
