<?php

declare(strict_types=1);

namespace App\Domain\Asset\Events;

use Carbon\Carbon;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class BasketValueCalculated extends ShouldBeStored
{
    public function __construct(
        public string $basketCode,
        public array $exchangeRates,
        public float $totalValue,
        public Carbon $calculatedAt
    ) {
    }
}
