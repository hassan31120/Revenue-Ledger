<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountType: string
{
    case Instructor = 'instructor';

    /**
     * The platform's own account. Modelling it explicitly — rather than treating
     * the platform's cut as "whatever is left over" — is what makes conservation
     * provable: every minor unit of every payment lands in some account.
     */
    case Platform = 'platform';
}
