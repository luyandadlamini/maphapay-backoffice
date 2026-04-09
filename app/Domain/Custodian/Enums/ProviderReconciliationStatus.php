<?php

declare(strict_types=1);

namespace App\Domain\Custodian\Enums;

enum ProviderReconciliationStatus: string
{
    case PENDING = 'pending';
    case MATCHED = 'matched';
    case EXCEPTION = 'exception';
}
