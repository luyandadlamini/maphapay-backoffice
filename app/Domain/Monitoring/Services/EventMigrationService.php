<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\EventMigration;
use App\Domain\Shared\EventSourcing\EventRouterInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Handles batch migration of events from shared stored_events
 * to domain-specific tables.
 */
class EventMigrationService
{
    public function __construct(
        private readonly EventRouterInterface $eventRouter,
        private readonly EventMigrationValidator $validator,
    ) {
    }

    /**
     * Get a migration plan showing what would be migrated.
     *
     * @return array<string, array{source: string, target: string, count: int, aliases: string[]}>
     */
    public function getMigrationPlan(?string $domain = null): array
    {
        $plan = [];
        $domains = $domain ? [$domain] : array_keys($this->eventRouter->getDomainTableMap());

        foreach ($domains as $domainName) {
            $targetTable = $this->eventRouter->resolveTableForDomain($domainName);
            $sourceTable = 'stored_events';

            if ($targetTable === $sourceTable) {
                continue;
            }

            $aliases = $this->getDomainEventAliases($domainName);

            if (empty($aliases)) {
                continue;
            }

            $count = Schema::hasTable($sourceTable)
                ? DB::table($sourceTable)->whereIn('event_class', $aliases)->count()
                : 0;

            if ($count > 0) {
                $plan[$domainName] = [
                    'source'  => $sourceTable,
                    'target'  => $targetTable,
                    'count'   => $count,
                    'aliases' => $aliases,
                ];
            }
        }

        return $plan;
    }

    /**
     * Migrate events for a domain from stored_events to domain table.
     */
    public function migrate(string $domain, int $batchSize = 1000, bool $dryRun = false): EventMigration
    {
        $lock = Cache::lock("event-migration:{$domain}", 300);
        if (! $lock->get()) {
            throw new RuntimeException("Migration already in progress for domain: {$domain}");
        }

        try {
            return $this->executeMigration($domain, $batchSize, $dryRun);
        } finally {
            $lock->release();
        }
    }

    private function executeMigration(string $domain, int $batchSize, bool $dryRun): EventMigration
    {
        $targetTable = $this->eventRouter->resolveTableForDomain($domain);
        $sourceTable = 'stored_events';
        $aliases = $this->getDomainEventAliases($domain);

        $totalCount = Schema::hasTable($sourceTable)
            ? DB::table($sourceTable)->whereIn('event_class', $aliases)->count()
            : 0;

        $migration = EventMigration::create([
            'domain'          => $domain,
            'source_table'    => $sourceTable,
            'target_table'    => $targetTable,
            'batch_size'      => $batchSize,
            'events_migrated' => 0,
            'events_total'    => $totalCount,
            'status'          => $dryRun ? 'dry_run' : 'running',
            'started_at'      => now(),
        ]);

        if ($dryRun) {
            $migration->update(['status' => 'dry_run', 'completed_at' => now()]);

            return $migration;
        }

        if ($totalCount === 0) {
            $migration->update(['status' => 'completed', 'completed_at' => now()]);

            return $migration;
        }

        if (! Schema::hasTable($targetTable)) {
            $migration->update([
                'status'          => 'completed',
                'events_migrated' => 0,
                'completed_at'    => now(),
                'error_message'   => "Target table '{$targetTable}' does not exist; migration skipped.",
            ]);

            return $migration;
        }

        try {
            $migrated = $this->executeBatchMigration(
                $sourceTable,
                $targetTable,
                $aliases,
                $batchSize,
                $migration,
            );

            $migration->update([
                'events_migrated' => $migrated,
                'status'          => 'completed',
                'completed_at'    => now(),
            ]);
        } catch (Throwable $e) {
            Log::error("Event migration failed for domain {$domain}", [
                'error'     => $e->getMessage(),
                'migration' => $migration->id,
            ]);

            $migration->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
        }

        return $migration;
    }

    /**
     * Verify a completed migration.
     *
     * @return array{valid: bool, checks: array<string, array{passed: bool, details: string}>}
     */
    public function verify(string $domain): array
    {
        $targetTable = $this->eventRouter->resolveTableForDomain($domain);

        return $this->validator->validate('stored_events', $targetTable, $domain);
    }

    /**
     * Rollback a migration by removing events from the target table.
     */
    public function rollback(int $migrationId): bool
    {
        $migration = EventMigration::find($migrationId);

        if (! $migration) {
            return false;
        }

        if ($migration->status !== 'completed' && $migration->status !== 'failed') {
            return false;
        }

        try {
            DB::beginTransaction();

            if (Schema::hasTable($migration->target_table)) {
                $aliases = $this->getDomainEventAliases($migration->domain);
                DB::table($migration->target_table)
                    ->whereIn('event_class', $aliases)
                    ->delete();
            }

            $migration->update(['status' => 'rolled_back', 'completed_at' => now()]);

            DB::commit();

            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Event migration rollback failed', [
                'migration' => $migrationId,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function executeBatchMigration(
        string $sourceTable,
        string $targetTable,
        array $aliases,
        int $batchSize,
        EventMigration $migration,
    ): int {
        $totalMigrated = 0;
        $lastId = 0;

        do {
            $batch = DB::table($sourceTable)
                ->whereIn('event_class', $aliases)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($targetTable, $batch) {
                $rows = $batch->map(function ($event) {
                    return (array) $event;
                })->toArray();

                // Remove id to let auto-increment assign new ones
                foreach ($rows as &$row) {
                    unset($row['id']);
                }
                unset($row);

                DB::table($targetTable)->insert($rows);
            });

            $totalMigrated += $batch->count();
            $lastId = $batch->last()->id;

            $migration->update(['events_migrated' => $totalMigrated]);
        } while ($batch->count() === $batchSize);

        return $totalMigrated;
    }

    /**
     * Get event aliases for a domain from the event class map.
     *
     * @return string[]
     */
    private function getDomainEventAliases(string $domain): array
    {
        $eventClassMap = config('event-sourcing.event_class_map', []);
        $aliases = [];

        foreach ($eventClassMap as $alias => $className) {
            if (preg_match('/App\\\\Domain\\\\' . preg_quote($domain, '/') . '\\\\/', $className)) {
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }
}
