<?php

declare(strict_types=1);

namespace App\Domain\Asset\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BasketRebalanced extends ShouldBeStored
{
    public function __construct(
        public string $basketCode,
        public array $newComponents,
        public array $oldComponents
    ) {
    }
}
