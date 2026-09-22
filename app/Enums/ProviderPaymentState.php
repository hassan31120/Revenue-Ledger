<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the PROVIDER says about a payment — never what we assume about it.
 */
enum ProviderPaymentState: string
{
    /** The provider confirms the money moved. */
    case Settled = 'settled';

    /** The provider has no record of this payment. No money moved. */
    case NotFound = 'not_found';

    /** The provider has the payment but has not finished it. Still ambiguous. */
    case Pending = 'pending';

    /** The provider has the payment and it definitively failed. */
    case Failed = 'failed';

    /**
     * Whether this state settles the question of where the money is.
     *
     * Pending does not: a pending payment may still settle, so a payout waiting on
     * one must stay unknown rather than being called a failure.
     */
    public function isConclusive(): bool
    {
        return $this !== self::Pending;
    }
}
