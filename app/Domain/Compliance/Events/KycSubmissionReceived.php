<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class KycSubmissionReceived extends ShouldBeStored
{
    public function __construct(
        public string $userUuid,
        public array $documents
    ) {
    }
}
