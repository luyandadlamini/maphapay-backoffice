<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\ValueObjects\CardTransaction;
use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Domain\CardSubscriptions\ValueObjects\DisputeInput;
use App\Models\User;
use LogicException;

class CardDisputeService
{
    public function open(User $user, CardTransaction $transaction, DisputeInput $input): CardDispute
    {
        throw new LogicException('not implemented');
    }

    public function syncStatus(CardDispute $dispute): CardDispute
    {
        throw new LogicException('not implemented');
    }

    public function recordChargebackAbuse(CardDispute $dispute, string $reason): void
    {
        throw new LogicException('not implemented');
    }
}
