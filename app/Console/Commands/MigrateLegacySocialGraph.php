<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Migrates social graph and device data from the legacy MaphaPay database into FinAegis.
 *
 * Prerequisites
 * -------------
 * - `database.connections.legacy` must be configured (points at the legacy MySQL DB).
 * - `migration_identity_map` must be populated (Phase 10 / migrateIdentityMap section).
 * - `friendships`, `friend_requests`, `money_requests`, and `mobile_devices` tables must exist.
 *
 * Sections
 * --------
 * identity_map            Reads legacy `users` and seeds migration_identity_map.
 * friendships             Copies accepted friendship pairs.
 * friend_requests         Copies pending friendship requests.
 * pending_money_requests  Copies open money requests.
 * device_tokens           Copies push notification device tokens into mobile_devices.
 */
class MigrateLegacySocialGraph extends Command
{
    private const SECTION_IDENTITY_MAP = 'identity_map';

    private const SECTION_FRIENDSHIPS = 'friendships';

    private const SECTION_FRIEND_REQUESTS = 'friend_requests';

    private const SECTION_PENDING_MONEY_REQUESTS = 'pending_money_requests';

    private const SECTION_DEVICE_TOKENS = 'device_tokens';

    /** @var list<string> */
    private const VALID_TABLES = [
        self::SECTION_IDENTITY_MAP,
        self::SECTION_FRIENDSHIPS,
        self::SECTION_FRIEND_REQUESTS,
        self::SECTION_PENDING_MONEY_REQUESTS,
        self::SECTION_DEVICE_TOKENS,
    ];

    protected $signature = 'legacy:migrate-social-graph
                            {--dry-run : Log planned work only; perform no writes}
                            {--table= : Run a single section: identity_map, friendships, friend_requests, pending_money_requests, device_tokens}
                            {--chunk=500 : Number of rows to process per batch}';

    protected $description = 'Migrate legacy social graph (identity map, friendships, requests, pending money requests)';

    private bool $dryRun = false;

    private int $chunkSize = 500;

    public function handle(): int
    {
        $legacyConfig = Config::get('database.connections.legacy');
        if (! is_array($legacyConfig) || $legacyConfig === []) {
            $this->error('Legacy database connection is not configured. Define database.connections.legacy in config/database.php.');

            return Command::FAILURE;
        }

        $this->dryRun = (bool) $this->option('dry-run');
        $this->chunkSize = max(1, (int) $this->option('chunk'));

        if ($this->dryRun) {
            $this->info('[dry-run] No database writes will be performed.');
        }

        $tableOption = $this->option('table');
        if ($tableOption !== null && $tableOption !== '') {
            if (! in_array($tableOption, self::VALID_TABLES, true)) {
                $this->error(sprintf(
                    'Invalid --table=%s. Allowed: %s.',
                    $tableOption,
                    implode(', ', self::VALID_TABLES)
                ));

                return Command::FAILURE;
            }

            return $this->runSection($tableOption);
        }

        $status = Command::SUCCESS;
        foreach (self::VALID_TABLES as $section) {
            $sectionStatus = $this->runSection($section);
            if ($sectionStatus !== Command::SUCCESS) {
                $status = $sectionStatus;
            }
        }

        return $status;
    }

    /**
     * Metadata columns to merge into migrated rows.
     *
     * @return array{migrated_from: string, migrated_at: Carbon}
     */
    public static function migrationRowMetadata(): array
    {
        return [
            'migrated_from' => 'legacy',
            'migrated_at'   => now(),
        ];
    }

    private function runSection(string $section): int
    {
        return match ($section) {
            self::SECTION_IDENTITY_MAP           => $this->migrateIdentityMap(),
            self::SECTION_FRIENDSHIPS            => $this->migrateFriendships(),
            self::SECTION_FRIEND_REQUESTS        => $this->migrateFriendRequests(),
            self::SECTION_PENDING_MONEY_REQUESTS => $this->migratePendingMoneyRequests(),
            self::SECTION_DEVICE_TOKENS          => $this->migrateDeviceTokens(),
            default                              => Command::FAILURE,
        };
    }

    // -------------------------------------------------------------------------
    // Section: identity map
    // -------------------------------------------------------------------------

    public function migrateIdentityMap(): int
    {
        $this->info('[identity_map] Reading legacy users…');

        $inserted = 0;
        $skipped = 0;

        DB::connection('legacy')
            ->table('users')
            ->select(['id', 'uuid', 'created_at'])
            ->orderBy('id')
            ->chunk($this->chunkSize, function ($rows) use (&$inserted, &$skipped): void {
                $now = Carbon::now();

                foreach ($rows as $row) {
                    $finaegisUuid = $row->uuid ?? null;

                    if ($finaegisUuid === null || $finaegisUuid === '') {
                        $this->warn(sprintf('[identity_map] Skipping legacy user %d — no UUID column.', $row->id));
                        $skipped++;

                        continue;
                    }

                    if ($this->dryRun) {
                        $this->line(sprintf('[dry-run] Would upsert identity_map: legacy_user_id=%d → %s', $row->id, $finaegisUuid));
                        $inserted++;

                        continue;
                    }

                    $affected = DB::table('migration_identity_map')
                        ->upsert(
                            [
                                'legacy_user_id'     => $row->id,
                                'finaegis_user_uuid' => $finaegisUuid,
                                'migrated_at'        => $now,
                            ],
                            uniqueBy: ['legacy_user_id'],
                            update: ['finaegis_user_uuid', 'migrated_at'],
                        );

                    $inserted += $affected;
                }
            });

        $this->info(sprintf('[identity_map] Done. inserted/updated=%d skipped=%d', $inserted, $skipped));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Section: friendships
    // -------------------------------------------------------------------------

    public function migrateFriendships(): int
    {
        $this->info('[friendships] Reading legacy accepted friendships…');

        $inserted = 0;
        $skipped = 0;

        // Build an in-memory identity map for fast lookups.
        $identityMap = $this->loadIdentityMap();

        if ($identityMap === []) {
            $this->warn('[friendships] Identity map is empty — run identity_map section first.');

            return $this->dryRun ? Command::SUCCESS : Command::FAILURE;
        }

        DB::connection('legacy')
            ->table('friendships')
            ->where('status', 'accepted')
            ->select(['user_id', 'friend_id', 'created_at'])
            ->orderBy('id')
            ->chunk($this->chunkSize, function ($rows) use ($identityMap, &$inserted, &$skipped): void {
                $now = Carbon::now();

                foreach ($rows as $row) {
                    $userId = $identityMap[$row->user_id] ?? null;
                    $friendId = $identityMap[$row->friend_id] ?? null;

                    if ($userId === null || $friendId === null) {
                        $skipped++;

                        continue;
                    }

                    if ($this->dryRun) {
                        $this->line(sprintf('[dry-run] Would upsert friendship: %d ↔ %d', $userId, $friendId));
                        $inserted++;

                        continue;
                    }

                    // Store both directions so each user's friend list is a simple WHERE user_id = ?
                    DB::table('friendships')->upsert(
                        [
                            ['user_id' => $userId, 'friend_id' => $friendId, 'status' => 'accepted', 'migrated_from' => 'legacy', 'migrated_at' => $now, 'created_at' => $now, 'updated_at' => $now],
                            ['user_id' => $friendId, 'friend_id' => $userId, 'status' => 'accepted', 'migrated_from' => 'legacy', 'migrated_at' => $now, 'created_at' => $now, 'updated_at' => $now],
                        ],
                        uniqueBy: ['user_id', 'friend_id'],
                        update: ['status', 'migrated_from', 'migrated_at', 'updated_at'],
                    );

                    $inserted += 2;
                }
            });

        $this->info(sprintf('[friendships] Done. rows=%d skipped=%d', $inserted, $skipped));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Section: friend requests
    // -------------------------------------------------------------------------

    public function migrateFriendRequests(): int
    {
        $this->info('[friend_requests] Reading legacy pending friend requests…');

        $inserted = 0;
        $skipped = 0;

        $identityMap = $this->loadIdentityMap();

        if ($identityMap === []) {
            $this->warn('[friend_requests] Identity map is empty — run identity_map section first.');

            return $this->dryRun ? Command::SUCCESS : Command::FAILURE;
        }

        DB::connection('legacy')
            ->table('friend_requests')
            ->where('status', 'pending')
            ->select(['sender_id', 'recipient_id', 'created_at'])
            ->orderBy('id')
            ->chunk($this->chunkSize, function ($rows) use ($identityMap, &$inserted, &$skipped): void {
                $now = Carbon::now();

                foreach ($rows as $row) {
                    $senderId = $identityMap[$row->sender_id] ?? null;
                    $recipientId = $identityMap[$row->recipient_id] ?? null;

                    if ($senderId === null || $recipientId === null) {
                        $skipped++;

                        continue;
                    }

                    if ($this->dryRun) {
                        $this->line(sprintf('[dry-run] Would upsert friend_request: %d → %d', $senderId, $recipientId));
                        $inserted++;

                        continue;
                    }

                    DB::table('friend_requests')->upsert(
                        [
                            'sender_id'     => $senderId,
                            'recipient_id'  => $recipientId,
                            'status'        => 'pending',
                            'migrated_from' => 'legacy',
                            'migrated_at'   => $now,
                            'created_at'    => $now,
                            'updated_at'    => $now,
                        ],
                        uniqueBy: ['sender_id', 'recipient_id'],
                        update: ['status', 'migrated_from', 'migrated_at', 'updated_at'],
                    );

                    $inserted++;
                }
            });

        $this->info(sprintf('[friend_requests] Done. rows=%d skipped=%d', $inserted, $skipped));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Section: pending money requests
    // -------------------------------------------------------------------------

    public function migratePendingMoneyRequests(): int
    {
        $this->info('[pending_money_requests] Reading legacy open money requests…');

        $inserted = 0;
        $skipped = 0;

        $identityMap = $this->loadIdentityMap();

        if ($identityMap === []) {
            $this->warn('[pending_money_requests] Identity map is empty — run identity_map section first.');

            return $this->dryRun ? Command::SUCCESS : Command::FAILURE;
        }

        DB::connection('legacy')
            ->table('money_requests')
            ->where('status', 'pending')
            ->select(['id', 'requester_id', 'recipient_id', 'amount', 'currency', 'note', 'created_at'])
            ->orderBy('id')
            ->chunk($this->chunkSize, function ($rows) use ($identityMap, &$inserted, &$skipped): void {
                $now = Carbon::now();

                foreach ($rows as $row) {
                    $requesterId = $identityMap[$row->requester_id] ?? null;
                    $recipientId = $identityMap[$row->recipient_id] ?? null;

                    if ($requesterId === null || $recipientId === null) {
                        $skipped++;

                        continue;
                    }

                    // Reject rows with no usable currency — do not silently default to SZL.
                    $assetCode = isset($row->currency) && is_string($row->currency) && trim($row->currency) !== ''
                        ? strtoupper(trim($row->currency))
                        : null;

                    if ($assetCode === null) {
                        $this->warn(sprintf('[pending_money_requests] Skipping money_request %s — missing currency', $row->id));
                        $skipped++;

                        continue;
                    }

                    // Normalise amount to major-unit string with 2 decimal places.
                    $amount = number_format((float) $row->amount, 2, '.', '');

                    if ($this->dryRun) {
                        $this->line(sprintf('[dry-run] Would insert money_request: requester=%d recipient=%d amount=%s %s', $requesterId, $recipientId, $amount, $assetCode));
                        $inserted++;

                        continue;
                    }

                    // Use the legacy UUID as the FinAegis UUID to preserve idempotency on re-run.
                    $legacyUuid = $row->id;

                    DB::table('money_requests')->upsert(
                        [
                            'id'                => $legacyUuid,
                            'requester_user_id' => $requesterId,
                            'recipient_user_id' => $recipientId,
                            'amount'            => $amount,
                            'asset_code'        => $assetCode,
                            'note'              => $row->note ?? null,
                            'status'            => 'pending',
                            'trx'               => null,
                            'created_at'        => $now,
                            'updated_at'        => $now,
                        ],
                        uniqueBy: ['id'],
                        update: ['amount', 'note', 'status', 'updated_at'],
                    );

                    $inserted++;
                }
            });

        $this->info(sprintf('[pending_money_requests] Done. rows=%d skipped=%d', $inserted, $skipped));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Section: device tokens
    // -------------------------------------------------------------------------

    public function migrateDeviceTokens(): int
    {
        $this->info('[device_tokens] Reading legacy device tokens…');

        $inserted = 0;
        $skipped = 0;

        $identityMap = $this->loadIdentityMap();

        if ($identityMap === []) {
            $this->warn('[device_tokens] Identity map is empty — run identity_map section first.');

            return $this->dryRun ? Command::SUCCESS : Command::FAILURE;
        }

        // Legacy table: device_tokens (user_id, device_id, platform, push_token, created_at)
        // Adjust column names here if the legacy schema differs.
        DB::connection('legacy')
            ->table('device_tokens')
            ->whereNotNull('push_token')
            ->select(['user_id', 'device_id', 'platform', 'push_token', 'created_at'])
            ->orderBy('id')
            ->chunk($this->chunkSize, function ($rows) use ($identityMap, &$inserted, &$skipped): void {
                $now = Carbon::now();

                foreach ($rows as $row) {
                    $finaegisUserId = $identityMap[$row->user_id] ?? null;

                    if ($finaegisUserId === null) {
                        $skipped++;

                        continue;
                    }

                    if ($this->dryRun) {
                        $this->line(sprintf('[dry-run] Would upsert device_token for user=%d platform=%s', $finaegisUserId, $row->platform ?? 'unknown'));
                        $inserted++;

                        continue;
                    }

                    DB::table('mobile_devices')->upsert(
                        [
                            'user_id'     => $finaegisUserId,
                            'device_id'   => $row->device_id ?? $row->push_token,
                            'platform'    => $row->platform ?? 'unknown',
                            'push_token'  => $row->push_token,
                            'app_version' => 'migrated',
                            'metadata'    => json_encode(['migrated_from' => 'legacy']),
                            'is_trusted'  => false,
                            'is_blocked'  => false,
                            'created_at'  => $now,
                            'updated_at'  => $now,
                        ],
                        uniqueBy: ['user_id', 'device_id'],
                        update: ['push_token', 'platform', 'updated_at'],
                    );

                    $inserted++;
                }
            });

        $this->info(sprintf('[device_tokens] Done. rows=%d skipped=%d', $inserted, $skipped));

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Load the full identity map into memory as legacy_user_id → finaegis_user_id.
     *
     * The FinAegis users table uses auto-increment integer IDs. We look up the
     * integer ID by joining on UUID so compat controllers can use integer FKs.
     *
     * @return array<int, int>
     */
    private function loadIdentityMap(): array
    {
        return DB::table('migration_identity_map as m')
            ->join('users as u', 'u.uuid', '=', 'm.finaegis_user_uuid')
            ->pluck('u.id', 'm.legacy_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
