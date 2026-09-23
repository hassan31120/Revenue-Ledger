<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountType: string
{
    case Instructor = 'instructor';

    case Platform = 'platform';
}
