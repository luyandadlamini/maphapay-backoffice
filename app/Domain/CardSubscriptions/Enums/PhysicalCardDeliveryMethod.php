<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum PhysicalCardDeliveryMethod: string
{
    case BranchCollection = 'branch_collection';
    case Courier = 'courier';
}
