<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardSubscriptionBillingResult: string
{
    case Success = 'success';
    case Failed = 'failed';
}
