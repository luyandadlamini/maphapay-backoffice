<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum PhysicalCardOrderStatus: string
{
    case Requested = 'requested';
    case Paid = 'paid';
    case Approved = 'approved';
    case Production = 'production';
    case Dispatched = 'dispatched';
    case ReadyForCollection = 'ready_for_collection';
    case Delivered = 'delivered';
    case Activated = 'activated';
    case Cancelled = 'cancelled';
}
