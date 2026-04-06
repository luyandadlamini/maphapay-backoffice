<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class KeyStored extends ShouldBeStored
{
    public function __construct(
        public string $walletId,
        public string $userId,
        public ?array $metadata = []
    ) {
    }
}
