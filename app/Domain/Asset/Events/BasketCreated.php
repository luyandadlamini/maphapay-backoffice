<?php

declare(strict_types=1);

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BasketCreated extends ShouldBeStored
{
    public function __construct(
        public string $basketCode,
        public string $name,
        public string $type,
        public array $components,
        public ?string $rebalanceFrequency = null
    ) {
    }
}
