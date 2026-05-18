<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventSourcing;

use App\Domain\Shared\Contracts\CarriesTenantContext;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Named test event that carries tenant context.
 * Used to test TenantAwareProjector with Spatie's reflection-based routing.
 */
final class TestTenantCarryingEvent extends ShouldBeStored implements CarriesTenantContext
{
    public function __construct(public readonly string $accountUuid)
    {
    }

    public function tenantAccountUuid(): string
    {
        return $this->accountUuid;
    }
}
