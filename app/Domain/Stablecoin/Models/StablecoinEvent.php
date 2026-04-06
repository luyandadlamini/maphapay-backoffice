<?php

declare(strict_types=1);

namespace App\Domain\Stablecoin\Models;

use App\Domain\Shared\EventSourcing\TenantAwareStoredEvent;

class StablecoinEvent extends TenantAwareStoredEvent
{
    public $table = 'stablecoin_events';
}
