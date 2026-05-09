<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardActorType: string
{
    case User = 'user';
    case Admin = 'admin';
    case System = 'system';
    case Processor = 'processor';
}
