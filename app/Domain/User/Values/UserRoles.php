<?php

declare(strict_types=1);

namespace App\Domain\User\Values;

enum UserRoles: string
{
    case BUSINESS = 'business';
    case PRIVATE = 'private';
    case ADMIN = 'admin';
}
