<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class GdprRequestReceived extends ShouldBeStored
{
    public function __construct(
        public string $userUuid,
        public string $requestType,
        public array $options
    ) {
    }
}
