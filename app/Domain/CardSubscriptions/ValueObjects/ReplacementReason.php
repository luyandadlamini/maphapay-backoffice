<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\ValueObjects;

enum ReplacementReason: string
{
    case EXPIRED = 'expired';
    case FRAUD = 'fraud';
    case LOST = 'lost';
    case STOLEN = 'stolen';
    case DAMAGED = 'damaged';
}
