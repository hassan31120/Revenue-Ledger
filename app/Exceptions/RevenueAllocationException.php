<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class RevenueAllocationException extends RuntimeException
{
    public static function noParticipatingInstructors(int $subscriptionId): self
    {
        return new self(
            "Subscription #{$subscriptionId} grants access to no courses, so there is no one to allocate revenue to. ".
            'Attach the purchased courses before allocating.'
        );
    }
}
