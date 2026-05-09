<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardKind: string
{
    case Virtual = 'virtual';
    case Physical = 'physical';
}
