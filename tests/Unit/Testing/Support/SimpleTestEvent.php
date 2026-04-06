<?php

declare(strict_types=1);

namespace Tests\Unit\Testing\Support;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class SimpleTestEvent extends ShouldBeStored
{
    public string $name;

    public int $value;
}
