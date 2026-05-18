<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventSourcing;

use App\Domain\Shared\EventSourcing\TenantAwareProjector;
use Stancl\Tenancy\Tenancy;

/**
 * Named test projector for TenantAwareProjector tests.
 * Captures whether tenancy was initialized when the handler ran.
 */
final class TestTenantAwareProjector extends TenantAwareProjector
{
    public ?bool $capturedTenancyState = null;

    public function onTestTenantCarryingEvent(TestTenantCarryingEvent $event): void
    {
        $this->capturedTenancyState = app(Tenancy::class)->initialized;
    }
}
