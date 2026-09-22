<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\ProviderPaymentState;

/**
 * The provider's answer to "what happened to this payment?".
 *
 * This is how an unknown payout is resolved after a timeout, and it is the only
 * thing allowed to settle that question.
 */
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
