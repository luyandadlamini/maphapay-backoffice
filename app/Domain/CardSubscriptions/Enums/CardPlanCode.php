<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardPlanCode: string
{
    case FREE_WALLET = 'FREE_WALLET';
    case VIRTUAL_LITE = 'VIRTUAL_LITE';
    case VIRTUAL_PLUS = 'VIRTUAL_PLUS';
    case PHYSICAL_CARD = 'PHYSICAL_CARD';
    case PREMIUM_CARD = 'PREMIUM_CARD';
    case MINOR_KHULA_CARD = 'MINOR_KHULA_CARD';
}
