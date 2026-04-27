<?php

declare(strict_types=1);

namespace App\Domain\Shared\Traits;

use Illuminate\Support\Facades\Config;

/**
 * Trait for models that should use the tenant database connection.
 *
 * Apply this trait to any Eloquent model that contains tenant-specific data.
 * The model will automatically use the 'tenant' connection which is
 * dynamically configured by stancl/tenancy based on the current tenant context.
 *
 * In testing environments with in-memory SQLite, this trait returns null
 * (which uses the default connection) to avoid issues with isolated
 * in-memory databases.
 *
 * Usage:
 * ```php
 * class Account extends Model
 * {
 *     use UsesTenantConnection;
 * }
 * ```
 *
 * Note: The 'tenant' connection must be configured in the database config.
 * For production, stancl/tenancy will configure this connection dynamically.
 */
trait UsesTenantConnection
{
    /**
     * Get the database connection for the model.
     *
     * When tenancy is initialized, stancl/tenancy creates a dynamic 'tenant'
     * connection pointing to the current tenant's database.
     *
     * For testing with in-memory SQLite, returns null to use the default
     * connection and avoid separate isolated databases.
     *
     * @return string|null
     */
    public function getConnectionName(): ?string
    {
        // In testing with in-memory SQLite, use the default connection
        // to avoid issues with isolated in-memory databases
        if ($this->shouldUseDefaultConnection()) {
            return null;
        }

        return 'tenant';
    }

    /**
     * Determine if the model should use the default connection instead of tenant.
     *
     * Returns true only in the testing environment where each connection may
     * be an isolated in-memory SQLite database. In all other environments the
     * model always uses the named 'tenant' connection so that:
     *
     * - Without a tenant context (login, registration, admin panel) the
     *   'tenant' connection config points to DB_DATABASE — the single app
     *   database — and queries succeed.
     * - With a tenant context stancl/tenancy overrides the 'tenant' connection
     *   to the per-tenant database as normal.
     *
     * The previous fallback to null/default when tenancy was not initialized
     * caused 500 errors on public routes (login, verifyOtp, completeProfile)
     * because the default connection (DB_CONNECTION env) may differ from the
     * named 'tenant' connection and may not have the accounts table.
     */
    protected function shouldUseDefaultConnection(): bool
    {
        return Config::get('app.env') === 'testing';
    }
}
