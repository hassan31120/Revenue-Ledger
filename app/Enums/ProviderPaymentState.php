<?php

declare(strict_types=1);

namespace App\Enums;

enum ProviderPaymentState: string
{
    case Settled = 'settled';

    case NotFound = 'not_found';

    case Pending = 'pending';

    case Failed = 'failed';

    public function isConclusive(): bool
    {
        return $this !== self::Pending;
    }
}
