<?php

declare(strict_types=1);

namespace App\Domain\Payment\Models;

use App\Domain\Shared\EventSourcing\TenantAwareStoredEvent;

class PaymentDeposit extends TenantAwareStoredEvent
{
    public $table = 'payment_deposits';
}
