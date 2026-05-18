<?php

declare(strict_types=1);

namespace App\Domain\Shared\Contracts;

/**
 * Marks a domain event that mutates tenant-scoped projections.
 *
 * Implementing this contract is the single source of truth telling
 * TenantAwareProjector / TenantAwareReactor which tenant to initialize
 * before invoking the handler. The returned UUID must be the account
 * UUID whose tenant DB the projection writes to.
 *
 * For transfer events that touch two accounts (and therefore potentially
 * two tenants), prefer emitting two events — one per side — each carrying
 * its own tenantAccountUuid. This keeps tenant initialization atomic.
 */
interface CarriesTenantContext
{
    public function tenantAccountUuid(): string;
}
