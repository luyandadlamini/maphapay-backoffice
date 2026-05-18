<?php

declare(strict_types=1);

namespace Tests\Fixtures\EventSourcing;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * Named test event with no tenant context.
 * Used to test that TenantAwareProjector passes through non-tenant events unmodified.
 */
final class TestPlainEvent extends ShouldBeStored
{
}
