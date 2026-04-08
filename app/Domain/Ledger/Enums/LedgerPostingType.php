<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

enum LedgerPostingType: string
{
    case SEND_MONEY = 'send_money';
    case REQUEST_MONEY_ACCEPT = 'request_money_accept';
}
