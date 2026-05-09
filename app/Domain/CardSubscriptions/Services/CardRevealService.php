<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\ValueObjects\RevealUrlResult;
use App\Models\User;

class CardRevealService
{
    public function mintRevealUrl(User $user, Card $card): RevealUrlResult
    {
        throw new \LogicException('not implemented');
    }
}
