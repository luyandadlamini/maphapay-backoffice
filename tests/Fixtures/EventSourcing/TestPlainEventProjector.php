<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventSourcing;

use App\Domain\Shared\EventSourcing\TenantAwareProjector;
use Stancl\Tenancy\Tenancy;

/**
 * Named test projector for plain (non-tenant) events.
 * Verifies tenancy is NOT initialized when handling tenant-agnostic events.
 */
final class TestPlainEventProjector extends TenantAwareProjector
{
    public ?bool $capturedTenancyState = null;

    public function onTestPlainEvent(TestPlainEvent $event): void
    {
        $this->capturedTenancyState = app(Tenancy::class)->initialized;
    }
}
