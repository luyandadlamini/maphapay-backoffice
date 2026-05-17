<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * Nightly CI guard that asserts nothing has written to the renamed legacy
 * central-DB account tables after the Phase-7 data migration.
 *
 * On first run (or when --reset-baseline is passed) it snapshots the current
 * row count and max updated_at for each table and stores it in
 * storage/app/central-account-baseline.json.
 *
 * On subsequent runs it compares the live state against the baseline and exits
 * non-zero if anything has changed — indicating an unexpected write.
 *
 * If either table does not exist yet (Phase 7 migration not yet run) the
 * command logs a notice and exits 0 so it does not fail pre-migration CI runs.
 */
class AssertNoCentralAccountAccessCommand extends Command
{
    protected $signature = 'maphapay:assert-no-central-account-access
                            {--reset-baseline : Overwrite the stored baseline with current live counts}';

    protected $description = 'Assert that no new rows have been written to the legacy central account tables since baseline.';

    private const BASELINE_PATH = 'central-account-baseline.json';

    private const TABLES = [
        'accounts_legacy_pre_canonicalization',
        'account_balances_legacy_pre_canonicalization',
    ];

    public function handle(): int
    {
        $snapshot = $this->buildSnapshot();

        if ($snapshot === null) {
            // One or both tables do not exist — migration not yet run; safe to skip.
            $this->info('[assert-no-central-account-access] Legacy tables not yet present — skipping guard (pre-migration state).');

            return self::SUCCESS;
        }

        if ($this->option('reset-baseline') || ! Storage::exists(self::BASELINE_PATH)) {
            $this->storeBaseline($snapshot);
            $this->info('[assert-no-central-account-access] Baseline recorded: ' . json_encode($snapshot));

            return self::SUCCESS;
        }

        $baseline = $this->loadBaseline();

        if ($baseline === null) {
            // Corrupt / unreadable baseline — reset it.
            $this->warn('[assert-no-central-account-access] Baseline file could not be parsed; resetting.');
            $this->storeBaseline($snapshot);

            return self::SUCCESS;
        }

        $violations = [];

        foreach (self::TABLES as $table) {
            $base = $baseline[$table] ?? null;
            $current = $snapshot[$table];

            if ($base === null) {
                // Table was not in baseline — treat as new; update baseline entry silently.
                continue;
            }

            if ($current['count'] > $base['count']) {
                $violations[] = sprintf(
                    'Table %s: row count grew from %d to %d.',
                    $table,
                    $base['count'],
                    $current['count'],
                );
            }

            if ($current['max_updated_at'] !== null && $base['max_updated_at'] !== null
                && $current['max_updated_at'] > $base['max_updated_at']) {
                $violations[] = sprintf(
                    'Table %s: max(updated_at) advanced from %s to %s.',
                    $table,
                    $base['max_updated_at'],
                    $current['max_updated_at'],
                );
            }
        }

        if ($violations !== []) {
            foreach ($violations as $violation) {
                $this->error('[assert-no-central-account-access] VIOLATION: ' . $violation);
            }

            return self::FAILURE;
        }

        $this->info('[assert-no-central-account-access] OK — no writes detected on legacy central account tables.');

        return self::SUCCESS;
    }

    /**
     * Build a snapshot of { table => { count, max_updated_at } } for both tables.
     * Returns null if any table does not exist (pre-migration state).
     *
     * @return array<string, array{count: int, max_updated_at: string|null}>|null
     */
    private function buildSnapshot(): ?array
    {
        $snapshot = [];

        foreach (self::TABLES as $table) {
            $tableExists = DB::connection('mysql')
                ->getSchemaBuilder()
                ->hasTable($table);

            if (! $tableExists) {
                return null;
            }

            /** @var object{count: int, max_updated_at: string|null} $row */
            $row = DB::connection('mysql')
                ->table($table)
                ->selectRaw('COUNT(*) as count, MAX(updated_at) as max_updated_at')
                ->first();

            $snapshot[$table] = [
                'count'          => (int) ($row->count ?? 0),
                'max_updated_at' => $row->max_updated_at ?? null,
            ];
        }

        return $snapshot;
    }

    /**
     * @param array<string, array{count: int, max_updated_at: string|null}> $snapshot
     */
    private function storeBaseline(array $snapshot): void
    {
        Storage::put(self::BASELINE_PATH, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array{count: int, max_updated_at: string|null}>|null
     */
    private function loadBaseline(): ?array
    {
        $raw = Storage::get(self::BASELINE_PATH);

        if ($raw === null) {
            return null;
        }

        try {
            /** @var array<string, array{count: int, max_updated_at: string|null}> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException) {
            return null;
        }
    }
}
