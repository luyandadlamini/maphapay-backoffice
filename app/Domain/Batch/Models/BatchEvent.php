<?php

declare(strict_types=1);

namespace App\Domain\Batch\Models;

use App\Domain\Shared\EventSourcing\TenantAwareStoredEvent;

class BatchEvent extends TenantAwareStoredEvent
{
    public $table = 'batch_events';
}
