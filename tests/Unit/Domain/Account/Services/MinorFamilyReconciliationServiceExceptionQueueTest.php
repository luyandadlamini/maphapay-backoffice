<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Services\MinorFamilyReconciliationService;
use App\Domain\Asset\Models\Asset;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorFamilyReconciliationServiceExceptionQueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePhase9Schema();

        DB::table('minor_family_reconciliation_exceptions')->delete();
        DB::table('minor_family_funding_attempts')->delete();
        DB::table('minor_family_funding_links')->delete();
        DB::table('mtn_momo_transactions')->delete();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );
    }

    #[Test]
    public function it_creates_missing_tenant_context_exception_with_source_metadata(): void
    {
        [, $attempt, $txn] = $this->makeFundingAttemptFixture([
            'attempt_tenant_id' => '',
        ]);

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldNotReceive('deposit');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $service = $this->app->make(MinorFamilyReconciliationService::class);

        $outcome = $service->reconcile($txn, 'callback');

        $this->assertFalse($outcome->isReconciled());
        $this->assertDatabaseHas('minor_family_reconciliation_exceptions', [
            'mtn_momo_transaction_id' => $txn->id,
            'reason_code'             => 'missing_tenant_context',
            'source'                  => 'callback',
            'status'                  => 'open',
            'occurrence_count'        => 1,
        ]);

        $this->assertSame(
            1,
            DB::table('minor_family_reconciliation_exceptions')
                ->where('mtn_momo_transaction_id', $txn->id)
                ->where('reason_code', 'missing_tenant_context')
                ->count(),
        );

        $exceptionRow = DB::table('minor_family_reconciliation_exceptions')
            ->where('mtn_momo_transaction_id', $txn->id)
            ->where('reason_code', 'missing_tenant_context')
            ->first();

        $this->assertNotNull($exceptionRow);

        $metadata = json_decode((string) $exceptionRow->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('callback', $metadata['reconciliation_source'] ?? null);
        $this->assertSame('unresolved', $metadata['reconciliation_outcome'] ?? null);
        $this->assertSame('missing_tenant_context', $metadata['reconciliation_reason_code'] ?? null);
        $this->assertSame($txn->mtn_reference_id, $metadata['mtn_reference_id'] ?? null);

        $this->assertNotNull($exceptionRow->sla_due_at);
        $slaHours = max(1, (int) config('minor_family.reconciliation_exception.sla_review_hours', 24));
        $expectedDue = Carbon::parse((string) $exceptionRow->first_seen_at)->addHours($slaHours);
        $this->assertLessThanOrEqual(
            2,
            abs(Carbon::parse((string) $exceptionRow->sla_due_at)->diffInSeconds($expectedDue)),
        );
    }

    #[Test]
    public function it_dedupes_unresolved_outcome_exceptions_and_updates_occurrence_on_replay(): void
    {
        $user = User::factory()->create();

        $txn = MtnMomoTransaction::query()->create([
            'id'               => (string) Str::uuid(),
            'user_id'          => $user->id,
            'idempotency_key'  => (string) Str::uuid(),
            'type'             => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
            'amount'           => '50.00',
            'currency'         => 'SZL',
            'status'           => MtnMomoTransaction::STATUS_SUCCESSFUL,
            'party_msisdn'     => '26876120000',
            'mtn_reference_id' => (string) Str::uuid(),
        ]);
        $txn->forceFill([
            'context_type' => MinorFamilyFundingAttempt::class,
            'context_uuid' => (string) Str::uuid(),
        ])->save();

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldNotReceive('deposit');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $service = $this->app->make(MinorFamilyReconciliationService::class);

        $firstOutcome = $service->reconcile($txn, 'status_poll');
        $secondOutcome = $service->reconcile($txn->fresh() ?? $txn, 'reconcile_command');

        $this->assertFalse($firstOutcome->isReconciled());
        $this->assertFalse($secondOutcome->isReconciled());

        $this->assertSame(
            1,
            DB::table('minor_family_reconciliation_exceptions')
                ->where('mtn_momo_transaction_id', $txn->id)
                ->where('reason_code', 'unresolved_outcome')
                ->count(),
        );

        $this->assertDatabaseHas('minor_family_reconciliation_exceptions', [
            'mtn_momo_transaction_id' => $txn->id,
            'reason_code'             => 'unresolved_outcome',
            'source'                  => 'reconcile_command',
            'status'                  => 'open',
            'occurrence_count'        => 2,
        ]);

        $exceptionRow = DB::table('minor_family_reconciliation_exceptions')
            ->where('mtn_momo_transaction_id', $txn->id)
            ->where('reason_code', 'unresolved_outcome')
            ->first();

        $this->assertNotNull($exceptionRow);

        $metadata = json_decode((string) $exceptionRow->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('reconcile_command', $metadata['reconciliation_source'] ?? null);
        $this->assertSame('unresolved', $metadata['reconciliation_outcome'] ?? null);
        $this->assertSame('unresolved_outcome', $metadata['reconciliation_reason_code'] ?? null);
        $this->assertSame($txn->mtn_reference_id, $metadata['mtn_reference_id'] ?? null);
        $this->assertSame('funding_or_support_context_unresolved', $metadata['resolution_path'] ?? null);
    }

    #[Test]
    public function it_resolves_open_exception_when_reconciliation_outcome_becomes_reconciled(): void
    {
        [$minorAccount, $attempt, $txn] = $this->makeFundingAttemptFixture();

        DB::table('minor_family_reconciliation_exceptions')->insert([
            'id'                      => (string) Str::uuid(),
            'mtn_momo_transaction_id' => $txn->id,
            'reason_code'             => 'unresolved_outcome',
            'status'                  => 'open',
            'source'                  => 'reconcile_command',
            'occurrence_count'        => 1,
            'metadata'                => json_encode(['seed' => true], JSON_THROW_ON_ERROR),
            'first_seen_at'           => now()->subMinutes(10),
            'last_seen_at'            => now()->subMinutes(5),
            'sla_due_at'              => now()->addHours(4),
            'sla_escalated_at'        => null,
            'resolved_at'             => null,
            'created_at'              => now()->subMinutes(10),
            'updated_at'              => now()->subMinutes(5),
        ]);

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldReceive('deposit')
            ->once()
            ->with(
                $minorAccount->uuid,
                'SZL',
                '10000',
                Mockery::type('string'),
                Mockery::type('array'),
            )
            ->andReturn('wallet-credit-success');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $service = $this->app->make(MinorFamilyReconciliationService::class);
        $outcome = $service->reconcile($txn->fresh() ?? $txn, 'status_poll');

        $this->assertTrue($outcome->isReconciled());
        $this->assertDatabaseHas('minor_family_reconciliation_exceptions', [
            'mtn_momo_transaction_id' => $txn->id,
            'reason_code'             => 'unresolved_outcome',
            'status'                  => 'resolved',
            'source'                  => 'status_poll',
        ]);

        $row = DB::table('minor_family_reconciliation_exceptions')
            ->where('mtn_momo_transaction_id', $txn->id)
            ->where('reason_code', 'unresolved_outcome')
            ->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->resolved_at);
    }

    /**
     * @return array{0: Account, 1: MinorFamilyFundingAttempt, 2: MtnMomoTransaction}
     */
    private function makeFundingAttemptFixture(array $overrides = []): array
    {
        $creator = User::factory()->create();
        $minorOwner = User::factory()->create();
        $creatorAccount = Account::factory()->create([
            'user_uuid' => $creator->uuid,
            'type'      => 'personal',
        ]);
        $minorAccount = Account::factory()->create([
            'user_uuid' => $minorOwner->uuid,
            'type'      => 'minor',
        ]);

        $link = MinorFamilyFundingLink::query()->create([
            'id'                      => (string) Str::uuid(),
            'tenant_id'               => (string) Str::uuid(),
            'minor_account_uuid'      => $minorAccount->uuid,
            'created_by_user_uuid'    => $creator->uuid,
            'created_by_account_uuid' => $creatorAccount->uuid,
            'title'                   => 'Support fee',
            'note'                    => 'Family support',
            'token'                   => (string) Str::uuid(),
            'status'                  => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode'             => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount'            => '100.00',
            'target_amount'           => null,
            'collected_amount'        => '0.00',
            'asset_code'              => 'SZL',
            'provider_options'        => ['mtn_momo'],
            'expires_at'              => now()->addDay(),
        ]);

        $attemptId = (string) Str::uuid();
        $txn = MtnMomoTransaction::query()->create([
            'id'               => (string) Str::uuid(),
            'user_id'          => $creator->id,
            'idempotency_key'  => (string) Str::uuid(),
            'type'             => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
            'amount'           => '100.00',
            'currency'         => 'SZL',
            'status'           => MtnMomoTransaction::STATUS_SUCCESSFUL,
            'party_msisdn'     => '26876123456',
            'mtn_reference_id' => (string) Str::uuid(),
        ]);
        $txn->forceFill([
            'context_type' => MinorFamilyFundingAttempt::class,
            'context_uuid' => $attemptId,
        ])->save();

        $attempt = MinorFamilyFundingAttempt::query()->create([
            'id'                      => $attemptId,
            'tenant_id'               => $overrides['attempt_tenant_id'] ?? $link->tenant_id,
            'funding_link_uuid'       => $link->id,
            'minor_account_uuid'      => $minorAccount->uuid,
            'status'                  => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
            'sponsor_name'            => 'Auntie',
            'sponsor_msisdn'          => '26876123456',
            'amount'                  => '100.00',
            'asset_code'              => 'SZL',
            'provider_name'           => 'mtn_momo',
            'provider_reference_id'   => $txn->mtn_reference_id,
            'mtn_momo_transaction_id' => $txn->id,
            'dedupe_hash'             => hash('sha256', (string) Str::uuid()),
        ]);

        return [$minorAccount, $attempt, $txn];
    }

    private function ensurePhase9Schema(): void
    {
        if (! Schema::hasTable('minor_family_funding_links')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_04_23_100000_create_minor_family_funding_links_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_family_funding_attempts')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_04_23_100100_create_minor_family_funding_attempts_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasColumns('mtn_momo_transactions', ['context_type', 'context_uuid'])) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_04_23_100300_add_minor_family_context_to_mtn_momo_transactions_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_family_reconciliation_exceptions')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_04_23_100400_create_minor_family_reconciliation_exceptions_table.php',
                '--force' => true,
            ]);
        }

        if (Schema::hasTable('minor_family_reconciliation_exceptions')
            && ! Schema::hasColumns('minor_family_reconciliation_exceptions', ['sla_due_at', 'sla_escalated_at'])) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_04_23_100410_add_sla_columns_to_minor_family_reconciliation_exceptions_table.php',
                '--force' => true,
            ]);
        }

        if (Schema::hasTable('minor_family_reconciliation_exceptions')
            && ! Schema::hasColumns('minor_family_reconciliation_exceptions', ['resolved_at'])) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_04_23_100430_add_resolved_at_to_minor_family_reconciliation_exceptions_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_family_reconciliation_exception_acknowledgments')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_04_23_100420_create_minor_family_reconciliation_exception_acknowledgments_table.php',
                '--force' => true,
            ]);
        }
    }
}
