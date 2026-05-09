<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardFeeStatus: string
{
    case Pending = 'pending';
    case Charged = 'charged';
    case Waived = 'waived';
    case Refunded = 'refunded';
    case Failed = 'failed';
}
