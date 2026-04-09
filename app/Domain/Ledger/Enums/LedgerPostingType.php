<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

enum LedgerPostingType: string
{
    case SEND_MONEY = 'send_money';
    case REQUEST_MONEY_ACCEPT = 'request_money_accept';
    case COMPENSATING_REVERSAL = 'compensating_reversal';
    case RECONCILIATION_ADJUSTMENT = 'reconciliation_adjustment';
    case MANUAL_ADJUSTMENT = 'manual_adjustment';
}
