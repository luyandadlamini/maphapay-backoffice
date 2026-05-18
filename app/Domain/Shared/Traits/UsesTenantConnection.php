<?php

declare(strict_types=1);

namespace App\Domain\Shared\Traits;

use App\Domain\Shared\Exceptions\TenantContextMissingException;
use Illuminate\Support\Facades\Config;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

/**
 * Trait for models that should use the tenant database connection.
 *
 * In testing env, returns null (default connection) so the suite can share
 * a single DB. In every other env, requires an initialized tenant context
 * and throws TenantContextMissingException otherwise — making cross-tenant
 * writes impossible by silent omission.
 */
trait UsesTenantConnection
{
    public function getConnectionName(): ?string
    {
        if ($this->shouldUseDefaultConnection()) {
            return null;
        }

        $this->assertTenantContextIsActive();

        return 'tenant';
    }

    protected function shouldUseDefaultConnection(): bool
    {
        return Config::get('app.env') === 'testing';
    }

    private function assertTenantContextIsActive(): void
    {
        /** @var Tenancy $tenancy */
        $tenancy = app(Tenancy::class);

        if (! $tenancy->initialized || ! ($tenancy->tenant instanceof TenantContract)) {
            throw TenantContextMissingException::forModel(static::class);
        }
    }
}
