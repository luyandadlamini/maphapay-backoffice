<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Listeners;

use App\Domain\CardIssuance\Events\AuthorizationApproved;
use App\Domain\CardIssuance\Events\AuthorizationDeclined;
use App\Domain\CardIssuance\Events\CardProvisioned;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class EmitCardLifecycleAuditLog implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(CardProvisioned|AuthorizationApproved|AuthorizationDeclined $event): void
    {
        //
    }
}
