<?php

declare(strict_types=1);

namespace App\Domain\Custodian\Enums;

enum ProviderSettlementStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
