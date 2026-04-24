<?php

declare(strict_types=1);

namespace App\Domain\Account\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class ChoreCreated extends ShouldBeStored
{
    public function __construct(
        public readonly string $choreId,
        public readonly string $guardianAccountUuid,
        public readonly string $minorAccountUuid,
        public readonly string $title,
        public readonly ?string $description,
        public readonly int $payoutPoints,
    ) {
    }
}