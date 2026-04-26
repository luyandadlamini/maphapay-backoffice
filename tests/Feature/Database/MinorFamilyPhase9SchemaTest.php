<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorFamilyPhase9SchemaTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function it_creates_the_minor_family_phase_9a_schema(): void
    {
        $this->prepareMinorFamilyPhase9Schema();

        $this->assertTrue(Schema::hasTable('minor_family_funding_links'));
        $this->assertTrue(Schema::hasColumns('minor_family_funding_links', [
            'id',
            'tenant_id',
            'minor_account_uuid',
            'created_by_user_uuid',
            'created_by_account_uuid',
            'title',
            'note',
            'token',
            'status',
            'amount_mode',
            'fixed_amount',
            'target_amount',
            'collected_amount',
            'asset_code',
            'provider_options',
            'expires_at',
            'last_funded_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('minor_family_funding_attempts'));
        $this->assertTrue(Schema::hasColumns('minor_family_funding_attempts', [
            'id',
            'tenant_id',
            'funding_link_uuid',
            'minor_account_uuid',
            'status',
            'sponsor_name',
            'sponsor_msisdn',
            'amount',
            'asset_code',
            'provider_name',
            'provider_reference_id',
            'mtn_momo_transaction_id',
            'wallet_credited_at',
            'failed_reason',
            'dedupe_hash',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('minor_family_support_transfers'));
        $this->assertTrue(Schema::hasColumns('minor_family_support_transfers', [
            'id',
            'tenant_id',
            'minor_account_uuid',
            'actor_user_uuid',
            'source_account_uuid',
            'status',
            'provider_name',
            'recipient_name',
            'recipient_msisdn',
            'amount',
            'asset_code',
            'note',
            'provider_reference_id',
            'mtn_momo_transaction_id',
            'wallet_refunded_at',
            'failed_reason',
            'idempotency_key',
            'created_at',
            'updated_at',
        ]));

        $this->assertSame(
            'pending_provider',
            $this->columnDefault('minor_family_funding_attempts', 'status'),
        );
        $this->assertSame(
            'pending_provider',
            $this->columnDefault('minor_family_support_transfers', 'status'),
        );

        $this->assertTrue(Schema::hasColumns('mtn_momo_transactions', [
            'context_type',
            'context_uuid',
        ]));

        $this->assertTrue(Schema::hasIndex('minor_family_funding_links', 'minor_family_funding_links_token_unique'));
        $this->assertTrue(Schema::hasIndex('minor_family_funding_links', 'minor_family_funding_links_tenant_minor_status_index'));

        $this->assertTrue(Schema::hasIndex('minor_family_funding_attempts', 'minor_family_funding_attempts_dedupe_hash_unique'));
        $this->assertTrue(Schema::hasIndex('minor_family_funding_attempts', 'minor_family_funding_attempts_funding_link_uuid_index'));
        $this->assertTrue(Schema::hasIndex('minor_family_funding_attempts', 'minor_family_funding_attempts_tenant_minor_status_index'));

        $this->assertTrue(Schema::hasIndex('minor_family_support_transfers', 'minor_family_support_transfers_tenant_actor_idempotency_unique'));
        $this->assertTrue(Schema::hasIndex('minor_family_support_transfers', 'minor_family_support_transfers_tenant_minor_status_index'));

        $this->assertTrue(Schema::hasIndex('mtn_momo_transactions', 'mtn_momo_transactions_context_type_context_uuid_index'));
    }

    #[Test]
    public function it_enforces_unique_tokens_on_minor_family_funding_links(): void
    {
        $this->prepareMinorFamilyPhase9Schema();

        $this->insertMinorFamilyFundingLink([
            'token' => 'shared-token',
        ]);

        $this->expectException(QueryException::class);

        $this->insertMinorFamilyFundingLink([
            'token' => 'shared-token',
        ]);
    }

    #[Test]
    public function it_enforces_unique_dedupe_hashes_on_minor_family_funding_attempts(): void
    {
        $this->prepareMinorFamilyPhase9Schema();

        $this->insertMinorFamilyFundingAttempt([
            'dedupe_hash' => 'shared-dedupe-hash',
        ]);

        $this->expectException(QueryException::class);

        $this->insertMinorFamilyFundingAttempt([
            'dedupe_hash' => 'shared-dedupe-hash',
        ]);
    }

    #[Test]
    public function it_enforces_scoped_unique_idempotency_keys_on_minor_family_support_transfers(): void
    {
        $this->prepareMinorFamilyPhase9Schema();

        $idempotencyKey = 'shared-idempotency-key';
        $actorUserUuid = (string) Str::uuid();

        $this->insertMinorFamilySupportTransfer([
            'idempotency_key' => $idempotencyKey,
            'actor_user_uuid' => $actorUserUuid,
        ]);

        // Same idempotency key is allowed when actor scope differs.
        $this->insertMinorFamilySupportTransfer([
            'idempotency_key' => $idempotencyKey,
            'actor_user_uuid' => (string) Str::uuid(),
        ]);

        // Same actor + key is allowed when tenant scope differs.
        $this->insertMinorFamilySupportTransfer([
            'tenant_id'       => 'tenant-2',
            'idempotency_key' => $idempotencyKey,
            'actor_user_uuid' => $actorUserUuid,
        ]);

        $this->expectException(QueryException::class);

        $this->insertMinorFamilySupportTransfer([
            'idempotency_key' => $idempotencyKey,
            'actor_user_uuid' => $actorUserUuid,
        ]);
    }

    #[Test]
    public function it_does_not_repair_existing_minor_family_phase_9_tables(): void
    {
        $this->prepareMinorFamilyPhase9Schema();
        $this->expectException(QueryException::class);

        $this->runMinorFamilyPhase9Migrations();
    }

    private function prepareMinorFamilyPhase9Schema(): void
    {
        $this->resetMinorFamilyPhase9Schema();
        $this->runMinorFamilyPhase9Migrations();
    }

    private function resetMinorFamilyPhase9Schema(): void
    {
        Schema::dropIfExists('minor_family_funding_links');
        Schema::dropIfExists('minor_family_funding_attempts');
        Schema::dropIfExists('minor_family_support_transfers');

        if (Schema::hasIndex('mtn_momo_transactions', 'mtn_momo_transactions_context_type_context_uuid_index')) {
            Schema::table('mtn_momo_transactions', function (Blueprint $table): void {
                $table->dropIndex('mtn_momo_transactions_context_type_context_uuid_index');
            });
        }

        if (Schema::hasColumn('mtn_momo_transactions', 'context_uuid')) {
            Schema::table('mtn_momo_transactions', function (Blueprint $table): void {
                $table->dropColumn('context_uuid');
            });
        }

        if (Schema::hasColumn('mtn_momo_transactions', 'context_type')) {
            Schema::table('mtn_momo_transactions', function (Blueprint $table): void {
                $table->dropColumn('context_type');
            });
        }
    }

    private function insertMinorFamilyFundingLink(array $overrides = []): void
    {
        DB::table('minor_family_funding_links')->insert(array_merge([
            'id'                      => (string) Str::uuid(),
            'tenant_id'               => 'tenant-1',
            'minor_account_uuid'      => (string) Str::uuid(),
            'created_by_user_uuid'    => (string) Str::uuid(),
            'created_by_account_uuid' => (string) Str::uuid(),
            'title'                   => 'Family support',
            'note'                    => null,
            'token'                   => (string) Str::uuid(),
            'status'                  => 'active',
            'amount_mode'             => 'fixed',
            'fixed_amount'            => '100.00',
            'target_amount'           => null,
            'collected_amount'        => '0',
            'asset_code'              => 'SZL',
            'provider_options'        => null,
            'expires_at'              => null,
            'last_funded_at'          => null,
            'created_at'              => now(),
            'updated_at'              => now(),
        ], $overrides));
    }

    private function insertMinorFamilyFundingAttempt(array $overrides = []): void
    {
        DB::table('minor_family_funding_attempts')->insert(array_merge([
            'id'                      => (string) Str::uuid(),
            'tenant_id'               => 'tenant-1',
            'funding_link_uuid'       => (string) Str::uuid(),
            'minor_account_uuid'      => (string) Str::uuid(),
            'status'                  => 'pending_provider',
            'sponsor_name'            => 'Sponsor',
            'sponsor_msisdn'          => '+26876000000',
            'amount'                  => '100.00',
            'asset_code'              => 'SZL',
            'provider_name'           => 'mtn_momo',
            'provider_reference_id'   => null,
            'mtn_momo_transaction_id' => null,
            'wallet_credited_at'      => null,
            'failed_reason'           => null,
            'dedupe_hash'             => (string) Str::uuid(),
            'created_at'              => now(),
            'updated_at'              => now(),
        ], $overrides));
    }

    private function insertMinorFamilySupportTransfer(array $overrides = []): void
    {
        DB::table('minor_family_support_transfers')->insert(array_merge([
            'id'                      => (string) Str::uuid(),
            'tenant_id'               => 'tenant-1',
            'minor_account_uuid'      => (string) Str::uuid(),
            'actor_user_uuid'         => (string) Str::uuid(),
            'source_account_uuid'     => (string) Str::uuid(),
            'status'                  => 'pending_provider',
            'provider_name'           => 'mtn_momo',
            'recipient_name'          => 'Recipient',
            'recipient_msisdn'        => '+26876000001',
            'amount'                  => '50.00',
            'asset_code'              => 'SZL',
            'note'                    => null,
            'provider_reference_id'   => null,
            'mtn_momo_transaction_id' => null,
            'wallet_refunded_at'      => null,
            'failed_reason'           => null,
            'idempotency_key'         => (string) Str::uuid(),
            'created_at'              => now(),
            'updated_at'              => now(),
        ], $overrides));
    }

    private function runMinorFamilyPhase9Migrations(): void
    {
        $migrations = [
            '2026_04_23_100000_create_minor_family_funding_links_table.php',
            '2026_04_23_100100_create_minor_family_funding_attempts_table.php',
            '2026_04_23_100200_create_minor_family_support_transfers_table.php',
            '2026_04_23_100250_scope_minor_family_support_transfer_idempotency_unique.php',
            '2026_04_23_100300_add_minor_family_context_to_mtn_momo_transactions_table.php',
            '2026_04_23_100350_update_minor_family_status_defaults_to_pending_provider.php',
        ];

        foreach ($migrations as $migrationFile) {
            (require base_path("database/migrations/{$migrationFile}"))->up();
        }
    }

    private function columnDefault(string $table, string $column): ?string
    {
        if (DB::getDriverName() === 'sqlite') {
            $columns = DB::select("PRAGMA table_info('{$table}')");

            foreach ($columns as $definition) {
                if (
                    is_object($definition)
                    && property_exists($definition, 'name')
                    && $definition->name === $column
                    && property_exists($definition, 'dflt_value')
                ) {
                    $default = $definition->dflt_value;

                    if (! is_string($default)) {
                        return null;
                    }

                    return trim($default, "'\"");
                }
            }

            return null;
        }

        $row = DB::selectOne(
            'select column_default from information_schema.columns where table_schema = database() and table_name = ? and column_name = ? limit 1',
            [$table, $column],
        );

        if (is_object($row) && property_exists($row, 'column_default') && is_string($row->column_default)) {
            return $row->column_default;
        }

        if (
            ! preg_match('/^[a-z0-9_]+$/i', $table)
            || ! preg_match('/^[a-z0-9_]+$/i', $column)
        ) {
            return null;
        }

        $fallback = DB::selectOne(
            sprintf('SHOW COLUMNS FROM `%s` WHERE Field = ?', $table),
            [$column],
        );

        if (! is_object($fallback) || ! property_exists($fallback, 'Default') || ! is_string($fallback->Default)) {
            return null;
        }

        return $fallback->Default;
    }
}
