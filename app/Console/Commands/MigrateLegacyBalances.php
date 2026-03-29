<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\Shared\Money\MoneyConverter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

class MigrateLegacyBalances extends Command
{
    protected $signature = 'legacy:migrate-balances
                            {--dry-run : Log planned work only; perform no writes}
                            {--snapshot : Take a new balance snapshot (default: use existing snapshot)}
                            {--chunk=500 : Number of rows to process per batch}
                            {--threshold=0.01 : Parity check tolerance in major units (default 0.01)}
                            {--cohort= : Comma-separated list of legacy user IDs to process (default: all)}';

    protected $description = 'Phase 17: Backfill FinAegis wallet balances from legacy MaphaPay (Option A — rolling snapshot with delta reconciliation)';

    private const ASSET_CODE = 'SZL';

    private const SNAPSHOT_KEY = 'legacy_balance_snapshot_taken_at';

    private bool $dryRun = false;

    private int $chunkSize = 500;

    private float $threshold = 0.01;

    private Carbon $snapshotTime;

    public function handle(): int
    {
        $legacyConfig = Config::get('database.connections.legacy');
        if (! is_array($legacyConfig) || $legacyConfig === []) {
            $this->error('Legacy database connection is not configured. Define database.connections.legacy in config/database.php.');

            return Command::FAILURE;
        }

        $this->dryRun = (bool) $this->option('dry-run');
        $this->chunkSize = max(1, (int) $this->option('chunk'));
        $this->threshold = max(0, (float) $this->option('threshold'));

        if ($this->option('snapshot')) {
            $this->snapshotTime = now();
            $this->info('[snapshot] New snapshot timestamp: ' . $this->snapshotTime->toIso8601String());
        } else {
            $existing = Cache::get(self::SNAPSHOT_KEY);
            if ($existing) {
                $this->snapshotTime = Carbon::parse($existing);
                $this->info('[snapshot] Using existing snapshot from: ' . $this->snapshotTime->toIso8601String());
            } else {
                $this->snapshotTime = now();
                $this->warn('[snapshot] No existing snapshot found — using now: ' . $this->snapshotTime->toIso8601String());
            }
        }

        if ($this->dryRun) {
            $this->info('[dry-run] No database writes will be performed.');
        }

        $this->warn('⚠️  Prerequisites check:');
        $this->warn('   1. migration_identity_map must be populated (run: legacy:migrate-social-graph --table=identity_map)');
        $this->warn('   2. migration_delta_log must be capturing deltas (enable the observer on legacy)');
        $this->warn('   3. SZL asset must be seeded in FinAegis (Asset::firstOrCreate([code => SZL])');
        $this->newLine();

        if (! $this->confirm('Have you verified all prerequisites above?')) {
            $this->info('Aborted.');

            return Command::FAILURE;
        }

        $asset = Asset::query()->where('code', self::ASSET_CODE)->first();
        if (! $asset) {
            $this->error("SZL asset not found in FinAegis. Seed it first: Asset::firstOrCreate(['code' => 'SZL'], [...])");

            return Command::FAILURE;
        }

        $cohortIds = $this->getCohortIds();

        $this->info(sprintf('Processing %d user(s) in legacy cohort…', count($cohortIds)));

        $status = $this->migrateBalances($asset, $cohortIds);

        $this->newLine();
        if ($status === Command::SUCCESS) {
            $this->info('✅ Balance migration completed successfully.');
        } else {
            $this->error('❌ Balance migration completed with parity failures. Review output above.');
        }

        return $status;
    }

    /**
     * @param list<int> $cohortIds
     */
    private function migrateBalances(Asset $asset, array $cohortIds): int
    {
        $inserted = 0;
        $skipped = 0;
        $parityFailed = 0;
        $deltaApplied = 0;

        $identityMap = $this->loadIdentityMap();

        $legacyUsers = DB::connection('legacy')
            ->table('users')
            ->select(['id', 'balance', 'uuid'])
            ->when($cohortIds !== [], fn ($q) => $q->whereIn('id', $cohortIds))
            ->orderBy('id')
            ->chunk($this->chunkSize, function ($rows) use (
                $asset,
                $identityMap,
                &$inserted,
                &$skipped,
                &$parityFailed,
                &$deltaApplied,
            ): void {
                foreach ($rows as $row) {
                    $finaegisUuid = $identityMap[$row->id] ?? null;

                    if ($finaegisUuid === null) {
                        $this->warn(sprintf('  [%s] No identity map entry — skipping legacy user %d', $row->id, $row->id));
                        $skipped++;

                        continue;
                    }

                    $account = Account::query()->where('user_uuid', $finaegisUuid)->first();
                    if (! $account) {
                        $this->warn(sprintf('  [%s] No FinAegis account for finaegis_uuid=%s — skipping', $row->id, $finaegisUuid));
                        $skipped++;

                        continue;
                    }

                    $legacyBalanceMajor = (float) $row->balance;
                    $snapshotBalanceMajor = $this->getSnapshotBalance($row->id, $finaegisUuid);
                    $deltaAmount = $this->getDeltaAmount($row->id, $row->uuid);

                    $targetBalanceMajor = $snapshotBalanceMajor + $deltaAmount;
                    $diff = abs($legacyBalanceMajor - $targetBalanceMajor);

                    $this->line(sprintf(
                        '  [%s] legacy=%s snapshot=%s delta=%s target=%s (diff=%.4f)',
                        $row->id,
                        number_format($legacyBalanceMajor, 4),
                        number_format($snapshotBalanceMajor, 4),
                        number_format($deltaAmount, 4),
                        number_format($targetBalanceMajor, 4),
                        $diff,
                    ));

                    if ($diff > $this->threshold) {
                        $this->error(sprintf(
                            '  [%s] PARITY FAILURE: diff=%.4f > threshold=%.4f — FINAEgis writes will NOT be enabled for this user',
                            $row->id,
                            $diff,
                            $this->threshold,
                        ));
                        $parityFailed++;

                        continue;
                    }

                    if ($this->dryRun) {
                        $this->line(sprintf('  [%s] [dry-run] Would set %s balance to %s', $row->id, self::ASSET_CODE, $targetBalanceMajor));
                        $inserted++;

                        continue;
                    }

                    $amountMinor = (int) MoneyConverter::forAsset(
                        number_format($targetBalanceMajor, $asset->precision, '.', ''),
                        $asset,
                    );

                    try {
                        DB::transaction(function () use ($account, $asset, $amountMinor): void {
                            $balance = AccountBalance::query()
                                ->where('account_uuid', $account->uuid)
                                ->where('asset_code', $asset->code)
                                ->lockForUpdate()
                                ->first();

                            if ($balance) {
                                $balance->update(['balance' => $amountMinor]);
                            } else {
                                AccountBalance::create([
                                    'account_uuid' => $account->uuid,
                                    'asset_code'   => $asset->code,
                                    'balance'      => $amountMinor,
                                ]);
                            }
                        });

                        $this->info(sprintf('  [%s] ✓ Migrated %s %s (minor: %d)', $row->id, number_format($targetBalanceMajor, 4), self::ASSET_CODE, $amountMinor));
                        $inserted++;
                        $deltaApplied += $deltaAmount !== 0.0 ? 1 : 0;
                    } catch (Throwable $e) {
                        $this->error(sprintf('  [%s] FAILED: %s', $row->id, $e->getMessage()));
                        $parityFailed++;
                    }
                }
            });

        $this->newLine();
        $this->info(sprintf('Summary: migrated=%d skipped=%d parity_failed=%d deltas_applied=%d', $inserted, $skipped, $parityFailed, $deltaApplied));

        if ($parityFailed > 0) {
            $this->warn(sprintf('%d user(s) failed parity check — do NOT enable FinAegis writes for these users.', $parityFailed));
        }

        return $parityFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function getSnapshotBalance(int $legacyUserId, string $finaegisUuid): float
    {
        $row = DB::connection('legacy')
            ->table('migration_balance_snapshots')
            ->where('legacy_user_id', $legacyUserId)
            ->where('asset_code', self::ASSET_CODE)
            ->first();

        if ($row) {
            return (float) $row->balance_major;
        }

        return 0.0;
    }

    private function getDeltaAmount(int $legacyUserId, ?string $legacyUuid): float
    {
        if ($legacyUuid === null) {
            return 0.0;
        }

        $credits = (float) DB::connection('legacy')
            ->table('migration_delta_log')
            ->where('legacy_user_id', $legacyUserId)
            ->where('direction', 'credit')
            ->where('captured_at', '>=', $this->snapshotTime)
            ->sum('amount_major');

        $debits = (float) DB::connection('legacy')
            ->table('migration_delta_log')
            ->where('legacy_user_id', $legacyUserId)
            ->where('direction', 'debit')
            ->where('captured_at', '>=', $this->snapshotTime)
            ->sum('amount_major');

        return $credits - $debits;
    }

    /**
     * Load identity map: legacy_user_id → finaegis_user_uuid.
     *
     * @return array<int, string>
     */
    private function loadIdentityMap(): array
    {
        return DB::table('migration_identity_map as m')
            ->join('users as u', 'u.uuid', '=', 'm.finaegis_user_uuid')
            ->pluck('m.finaegis_user_uuid', 'm.legacy_user_id')
            ->map(fn ($uuid) => (string) $uuid)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function getCohortIds(): array
    {
        $cohortOption = $this->option('cohort');

        if ($cohortOption === null || $cohortOption === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('intval', explode(',', $cohortOption)),
            fn ($id) => $id > 0,
        ));
    }
}
