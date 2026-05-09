<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardTier: string
{
    case Standard = 'standard';
    case Plus = 'plus';
    case Premium = 'premium';
    case Khula = 'khula';
}
