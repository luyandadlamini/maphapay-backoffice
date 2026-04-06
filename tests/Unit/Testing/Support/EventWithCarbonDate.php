<?php

declare(strict_types=1);

namespace Tests\Unit\Testing\Support;

use Carbon\Carbon;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class EventWithCarbonDate extends ShouldBeStored
{
    public string $title;

    public Carbon $createdAt;

    public ?Carbon $updatedAt;
}
