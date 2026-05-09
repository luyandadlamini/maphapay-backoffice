<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Enums;

enum CardFeeType: string
{
    case Subscription = 'subscription';
    case FxMarkup = 'fx_markup';
    case Atm = 'atm';
    case VirtualCardReplacement = 'virtual_card_replacement';
    case PhysicalCardIssuance = 'physical_card_issuance';
    case PhysicalCardReplacement = 'physical_card_replacement';
    case ChargebackAbuse = 'chargeback_abuse';
    case ManualAdjustment = 'manual_adjustment';
}
