<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Throwable;

trait LazilyRefreshExistingMySqlSchema
{
    use RefreshDatabase {
        refreshDatabase as baseRefreshDatabase;
    }

    public function refreshDatabase(): void
    {
        $database = $this->app->make('db');

        $callback = function (): void {
            if (RefreshDatabaseState::$lazilyRefreshed) {
                return;
            }

            RefreshDatabaseState::$lazilyRefreshed = true;

            if (property_exists($this, 'mockConsoleOutput')) {
                $shouldMockOutput = $this->mockConsoleOutput;
                $this->mockConsoleOutput = false;
            }

            $this->baseRefreshDatabase();

            if (property_exists($this, 'mockConsoleOutput')) {
                $this->mockConsoleOutput = $shouldMockOutput;
            }
        };

        $database->beforeStartingTransaction($callback);
        $database->beforeExecuting($callback);

        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$lazilyRefreshed = false;
        });
    }

    protected function refreshTestDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            if ($this->shouldReuseExistingMySqlSchema()) {
                RefreshDatabaseState::$migrated = true;
            } else {
                $this->migrateDatabases();
                $this->app[Kernel::class]->setArtisan(null);
                $this->updateLocalCacheOfInMemoryDatabases();
                RefreshDatabaseState::$migrated = true;
            }
        }

        $this->beginDatabaseTransaction();
    }

    protected function shouldReuseExistingMySqlSchema(): bool
    {
        $flag = getenv('TEST_REUSE_EXISTING_MYSQL_SCHEMA');
        if ($flag !== false && filter_var($flag, FILTER_VALIDATE_BOOL) === false) {
            return false;
        }

        $connectionName = config('database.default');
        if (! is_string($connectionName) || $connectionName !== 'mysql') {
            return false;
        }

        try {
            $connection = $this->app->make('db')->connection($connectionName);

            if (! $connection->getSchemaBuilder()->hasTable('migrations')) {
                return false;
            }

            return $connection->table('migrations')->count() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
