<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardDisputeReason: string
{
    case Unrecognised = 'unrecognised';
    case Duplicate = 'duplicate';
    case WrongAmount = 'wrong_amount';
    case ServiceNotReceived = 'service_not_received';
    case Other = 'other';
}
