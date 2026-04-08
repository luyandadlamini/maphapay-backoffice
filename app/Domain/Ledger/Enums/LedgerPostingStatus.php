<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Enums;

enum LedgerPostingStatus: string
{
    case PENDING_POSTING = 'pending_posting';
    case POSTED = 'posted';
    case REVERSED = 'reversed';
    case ADJUSTED = 'adjusted';
}
