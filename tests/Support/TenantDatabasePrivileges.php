<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Probes whether the current test DB user can create stancl tenant databases.
 *
 * @see scripts/reset-local-mysql-test-access.sh (local GRANT CREATE, DROP ON *.*)
 */
final class TenantDatabasePrivileges
{
    public static function isInMemorySqlite(): bool
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = config("database.connections.{$connection}.database");

        return $driver === 'sqlite' && $database === ':memory:';
    }

    public static function canCreateTenantDatabases(): bool
    {
        if (self::isInMemorySqlite()) {
            return false;
        }

        try {
            $user = User::factory()->create();
            $team = Team::factory()->create(['user_id' => $user->id]);
            $tenant = Tenant::createFromTeam($team);

            $tenant->delete();
            $team->delete();
            $user->delete();

            return true;
        } catch (QueryException $e) {
            return ! str_contains($e->getMessage(), 'CREATE DATABASE')
                && ! str_contains($e->getMessage(), 'Access denied for user');
        }
    }
}
