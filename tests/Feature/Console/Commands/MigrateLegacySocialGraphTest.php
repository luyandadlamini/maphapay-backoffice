<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\MigrateLegacySocialGraph;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Integration tests for the legacy social graph migration command.
 *
 * The legacy DB is an in-memory SQLite connection whose tables are created
 * manually in setUp(). This means tests do not touch the real legacy schema
 * but verify the command's reading/mapping/writing behaviour end-to-end.
 */
#[Large]
final class MigrateLegacySocialGraphTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function createRoles(): void
    {
        // Skip role seeding — not needed for command-only tests.
    }

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.legacy', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('legacy');

        // Create minimal legacy schema in the in-memory SQLite DB.
        $legacy = DB::connection('legacy');
        $legacy->statement('CREATE TABLE IF NOT EXISTS users (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid       TEXT    NOT NULL,
            created_at TEXT
        )');
        $legacy->statement('CREATE TABLE IF NOT EXISTS friendships (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id    INTEGER NOT NULL,
            friend_id  INTEGER NOT NULL,
            status     TEXT    NOT NULL DEFAULT "accepted",
            created_at TEXT
        )');
        $legacy->statement('CREATE TABLE IF NOT EXISTS friend_requests (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id    INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            status       TEXT    NOT NULL DEFAULT "pending",
            created_at   TEXT
        )');
        $legacy->statement('CREATE TABLE IF NOT EXISTS money_requests (
            id           TEXT    PRIMARY KEY,
            requester_id INTEGER NOT NULL,
            recipient_id INTEGER NOT NULL,
            amount       REAL    NOT NULL,
            currency     TEXT    NOT NULL DEFAULT "SZL",
            note         TEXT,
            status       TEXT    NOT NULL DEFAULT "pending",
            created_at   TEXT
        )');
        $legacy->statement('CREATE TABLE IF NOT EXISTS device_tokens (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id      INTEGER NOT NULL,
            device_id    TEXT,
            platform     TEXT,
            push_token   TEXT,
            created_at   TEXT
        )');
    }

    // -------------------------------------------------------------------------
    // Guard: missing legacy connection
    // -------------------------------------------------------------------------

    #[Test]
    public function it_aborts_when_legacy_connection_is_not_configured(): void
    {
        Config::set('database.connections.legacy', null);
        DB::purge('legacy');

        $exitCode = Artisan::call('legacy:migrate-social-graph');

        $this->assertStringContainsString(
            'Legacy database connection is not configured',
            Artisan::output()
        );
        $this->assertSame(1, $exitCode);
    }

    // -------------------------------------------------------------------------
    // Guard: invalid --table option
    // -------------------------------------------------------------------------

    #[Test]
    public function it_rejects_invalid_table_option(): void
    {
        $exitCode = Artisan::call('legacy:migrate-social-graph', ['--table' => 'nope']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Invalid --table=', Artisan::output());
    }

    // -------------------------------------------------------------------------
    // migrationRowMetadata helper
    // -------------------------------------------------------------------------

    #[Test]
    public function migration_row_metadata_returns_expected_shape(): void
    {
        $meta = MigrateLegacySocialGraph::migrationRowMetadata();

        $this->assertSame('legacy', $meta['migrated_from']);
        $this->assertArrayHasKey('migrated_at', $meta);
        $this->assertNotNull($meta['migrated_at']);
    }

    // -------------------------------------------------------------------------
    // Dry-run: no writes to FinAegis DB
    // -------------------------------------------------------------------------

    #[Test]
    public function dry_run_performs_no_writes_to_finaegis(): void
    {
        // Seed a legacy user so we exercise the loop path too.
        $uuid = Str::uuid()->toString();
        DB::connection('legacy')->table('users')->insert(['id' => 1, 'uuid' => $uuid]);

        $watchedTables = [
            'migration_identity_map',
            'friendships',
            'friend_requests',
            'money_requests',
        ];
        if (Schema::hasTable('mobile_devices')) {
            $watchedTables[] = 'mobile_devices';
        }

        $rowCountsBefore = [];
        foreach ($watchedTables as $table) {
            $rowCountsBefore[$table] = DB::table($table)->count();
        }

        $output = new BufferedOutput();
        $exitCode = Artisan::call('legacy:migrate-social-graph', ['--dry-run' => true], $output);

        $this->assertSame(0, $exitCode);
        $commandOutput = $output->fetch();
        $this->assertStringContainsString('[dry-run]', $commandOutput);

        foreach ($watchedTables as $table) {
            $this->assertSame(
                $rowCountsBefore[$table],
                DB::table($table)->count(),
                "Dry-run must not change row count on {$table}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Identity map section
    // -------------------------------------------------------------------------

    #[Test]
    public function identity_map_inserts_legacy_user_uuid_into_map_table(): void
    {
        $uuid = Str::uuid()->toString();
        DB::connection('legacy')->table('users')->insert(['id' => 1, 'uuid' => $uuid]);

        $exitCode = Artisan::call('legacy:migrate-social-graph', ['--table' => 'identity_map']);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('migration_identity_map', [
            'legacy_user_id'     => 1,
            'finaegis_user_uuid' => $uuid,
        ]);
    }

    #[Test]
    public function identity_map_skips_legacy_users_without_a_uuid(): void
    {
        DB::connection('legacy')
            ->statement("INSERT INTO users (id, uuid) VALUES (1, '')");

        Artisan::call('legacy:migrate-social-graph', ['--table' => 'identity_map']);

        $this->assertDatabaseMissing('migration_identity_map', ['legacy_user_id' => 1]);
        $this->assertStringContainsString('no UUID column', Artisan::output());
    }

    #[Test]
    public function identity_map_is_idempotent_on_rerun(): void
    {
        $uuid = Str::uuid()->toString();
        DB::connection('legacy')->table('users')->insert(['id' => 1, 'uuid' => $uuid]);

        Artisan::call('legacy:migrate-social-graph', ['--table' => 'identity_map']);
        Artisan::call('legacy:migrate-social-graph', ['--table' => 'identity_map']);

        $this->assertSame(1, DB::table('migration_identity_map')
            ->where('legacy_user_id', 1)
            ->count());
    }

    // -------------------------------------------------------------------------
    // Friendships section
    // -------------------------------------------------------------------------

    #[Test]
    public function friendships_fails_when_identity_map_is_empty(): void
    {
        $exitCode = Artisan::call('legacy:migrate-social-graph', ['--table' => 'friendships']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Identity map is empty', Artisan::output());
    }

    #[Test]
    public function friendships_upserts_both_directions_for_accepted_pairs(): void
    {
        [$legacyA, $legacyB, $userA, $userB] = $this->seedTwoMappedUsers();

        DB::connection('legacy')->table('friendships')->insert([
            ['user_id' => $legacyA, 'friend_id' => $legacyB, 'status' => 'accepted'],
        ]);

        $exitCode = Artisan::call('legacy:migrate-social-graph', ['--table' => 'friendships']);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('friendships', ['user_id' => $userA->id, 'friend_id' => $userB->id, 'migrated_from' => 'legacy']);
        $this->assertDatabaseHas('friendships', ['user_id' => $userB->id, 'friend_id' => $userA->id, 'migrated_from' => 'legacy']);
    }

    #[Test]
    public function friendships_skips_rows_where_user_not_in_identity_map(): void
    {
        [$legacyA, , $userA] = $this->seedTwoMappedUsers();

        DB::connection('legacy')->table('friendships')->insert([
            ['user_id' => $legacyA, 'friend_id' => 999, 'status' => 'accepted'],
        ]);

        Artisan::call('legacy:migrate-social-graph', ['--table' => 'friendships']);

        $this->assertDatabaseEmpty('friendships');
    }

    // -------------------------------------------------------------------------
    // Friend requests section
    // -------------------------------------------------------------------------

    #[Test]
    public function friend_requests_fails_when_identity_map_is_empty(): void
    {
        $exitCode = Artisan::call('legacy:migrate-social-graph', ['--table' => 'friend_requests']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function friend_requests_inserts_pending_requests(): void
    {
        [$legacyA, $legacyB, $userA, $userB] = $this->seedTwoMappedUsers();

        DB::connection('legacy')->table('friend_requests')->insert([
            ['sender_id' => $legacyA, 'recipient_id' => $legacyB, 'status' => 'pending'],
        ]);

        $exitCode = Artisan::call('legacy:migrate-social-graph', ['--table' => 'friend_requests']);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('friend_requests', [
            'sender_id'     => $userA->id,
            'recipient_id'  => $userB->id,
            'status'        => 'pending',
            'migrated_from' => 'legacy',
        ]);
    }

    // -------------------------------------------------------------------------
    // Pending money requests section
    // -------------------------------------------------------------------------

    #[Test]
    public function pending_money_requests_fails_when_identity_map_is_empty(): void
    {
        $exitCode = Artisan::call('legacy:migrate-social-graph', ['--table' => 'pending_money_requests']);

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function pending_money_requests_inserts_open_requests_with_szl_asset_code(): void
    {
        [$legacyA, $legacyB, $userA, $userB] = $this->seedTwoMappedUsers();

        $legacyId = Str::uuid()->toString();
        DB::connection('legacy')->table('money_requests')->insert([
            'id'           => $legacyId,
            'requester_id' => $legacyA,
            'recipient_id' => $legacyB,
            'amount'       => 50.00,
            'currency'     => 'SZL',
            'note'         => 'lunch',
            'status'       => 'pending',
        ]);

        $exitCode = Artisan::call('legacy:migrate-social-graph', ['--table' => 'pending_money_requests']);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('money_requests', [
            'id'                => $legacyId,
            'requester_user_id' => $userA->id,
            'recipient_user_id' => $userB->id,
            'amount'            => '50.00',
            'asset_code'        => 'SZL',
            'note'              => 'lunch',
            'status'            => 'pending',
        ]);
    }

    #[Test]
    public function pending_money_requests_is_idempotent_on_rerun(): void
    {
        [$legacyA, $legacyB] = $this->seedTwoMappedUsers();

        $legacyId = Str::uuid()->toString();
        DB::connection('legacy')->table('money_requests')->insert([
            'id'           => $legacyId,
            'requester_id' => $legacyA,
            'recipient_id' => $legacyB,
            'amount'       => 25.00,
            'currency'     => 'SZL',
            'status'       => 'pending',
        ]);

        Artisan::call('legacy:migrate-social-graph', ['--table' => 'pending_money_requests']);
        Artisan::call('legacy:migrate-social-graph', ['--table' => 'pending_money_requests']);

        $this->assertSame(1, DB::table('money_requests')->where('id', $legacyId)->count());
    }

    // -------------------------------------------------------------------------
    // Single-section isolation via --table
    // -------------------------------------------------------------------------

    #[Test]
    public function table_option_runs_only_the_requested_section(): void
    {
        Artisan::call('legacy:migrate-social-graph', ['--table' => 'identity_map']);
        $output = Artisan::output();

        $this->assertStringContainsString('[identity_map]', $output);
        $this->assertStringNotContainsString('[friendships]', $output);
        $this->assertStringNotContainsString('[friend_requests]', $output);
        $this->assertStringNotContainsString('[pending_money_requests]', $output);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create two legacy DB users + matching FinAegis users + identity map rows.
     *
     * @return array{0: int, 1: int, 2: User, 3: User}
     */
    private function seedTwoMappedUsers(): array
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $legacyIdA = 1;
        $legacyIdB = 2;

        DB::connection('legacy')->table('users')->insert([
            ['id' => $legacyIdA, 'uuid' => $userA->uuid],
            ['id' => $legacyIdB, 'uuid' => $userB->uuid],
        ]);

        DB::table('migration_identity_map')->insert([
            ['legacy_user_id' => $legacyIdA, 'finaegis_user_uuid' => $userA->uuid, 'migrated_at' => now()],
            ['legacy_user_id' => $legacyIdB, 'finaegis_user_uuid' => $userB->uuid, 'migrated_at' => now()],
        ]);

        return [$legacyIdA, $legacyIdB, $userA, $userB];
    }
}
