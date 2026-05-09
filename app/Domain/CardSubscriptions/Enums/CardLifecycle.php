<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardLifecycle: string
{
    case Standard = 'standard';
    case MerchantLocked = 'merchant_locked';
    case SingleUse = 'single_use';
    case Trial = 'trial';
}
