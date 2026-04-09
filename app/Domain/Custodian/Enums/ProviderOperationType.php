<?php

declare(strict_types=1);

namespace App\Domain\Custodian\Enums;

enum ProviderOperationType: string
{
    case TRANSFER = 'transfer';
    case BALANCE_SYNC = 'balance_sync';
    case ACCOUNT_STATUS = 'account_status';
    case UNKNOWN = 'unknown';
}
