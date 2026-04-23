<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Domain\Asset\Models\Asset;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ReconcileMtnMomoTransactionsMinorFamilyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePhase9Schema();

        DB::table('minor_family_funding_attempts')->delete();
        DB::table('minor_family_funding_links')->delete();
        DB::table('minor_family_support_transfers')->delete();
        DB::table('mtn_momo_transactions')->delete();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name' => 'Swazi Lilangeni',
                'type' => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );
    }

    #[Test]
    public function reconciliation_command_fails_closed_when_phase_9a_context_row_has_missing_tenant_context(): void
    {
        [, $attempt, $txn] = $this->makeStuckFundingAttemptFixture([
            'attempt_tenant_id' => '',
        ]);

        Log::spy();

        $mtnClient = Mockery::mock(\App\Domain\MtnMomo\Services\MtnMomoClient::class);
        $mtnClient->shouldReceive('getRequestToPayStatus')
            ->once()
            ->with($txn->mtn_reference_id)
            ->andReturn(['status' => 'SUCCESSFUL']);
        $this->app->instance(\App\Domain\MtnMomo\Services\MtnMomoClient::class, $mtnClient);

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldNotReceive('deposit');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $exitCode = Artisan::call('mtn:reconcile-disbursements', [
            '--min-age' => 1,
        ]);
        $output = (string) preg_replace('/\e\[[\d;]*m/', '', Artisan::output());

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('settled=0', $output);
        $this->assertStringContainsString('unreconciled=1', $output);

        $this->assertDatabaseHas('minor_family_funding_attempts', [
            'id' => $attempt->id,
            'status' => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
            'tenant_id' => '',
        ]);

        $this->assertNull(MinorFamilyFundingAttempt::query()->findOrFail($attempt->id)->wallet_credited_at);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'MinorFamilyReconciliationService: missing tenant context for phase9 funding attempt'
                    && ($context['funding_attempt_id'] ?? null) !== null
                    && ($context['mtn_momo_transaction_id'] ?? null) !== null;
            })
            ->once();
    }

    #[Test]
    public function reconciliation_command_updates_phase_9a_funding_attempt_state_for_stuck_mtn_rows(): void
    {
        [$minorAccount, $attempt, $txn] = $this->makeStuckFundingAttemptFixture([
            'txn_created_at' => now()->subMinutes(20),
            'txn_updated_at' => now()->subMinutes(20),
            'attempt_created_at' => now()->subMinutes(20),
            'attempt_updated_at' => now()->subMinutes(20),
        ]);

        $mtnClient = Mockery::mock(\App\Domain\MtnMomo\Services\MtnMomoClient::class);
        $mtnClient->shouldReceive('getRequestToPayStatus')
            ->once()
            ->with($txn->mtn_reference_id)
            ->andReturn(['status' => 'SUCCESSFUL']);
        $this->app->instance(\App\Domain\MtnMomo\Services\MtnMomoClient::class, $mtnClient);

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldReceive('deposit')
            ->once()
            ->with(
                $minorAccount->uuid,
                'SZL',
                '15000',
                Mockery::type('string'),
                Mockery::type('array'),
            )
            ->andReturn('wallet-credit-reconcile');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $exitCode = Artisan::call('mtn:reconcile-disbursements', [
            '--min-age' => 1,
        ]);

        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('mtn_momo_transactions', [
            'id' => $txn->id,
            'status' => MtnMomoTransaction::STATUS_SUCCESSFUL,
        ]);

        $this->assertDatabaseHas('minor_family_funding_attempts', [
            'id' => $attempt->id,
            'status' => MinorFamilyFundingAttempt::STATUS_CREDITED,
        ]);

        $this->assertNotNull(MtnMomoTransaction::query()->findOrFail($txn->id)->wallet_credited_at);
        $this->assertNotNull(MinorFamilyFundingAttempt::query()->findOrFail($attempt->id)->wallet_credited_at);
    }

    #[Test]
    public function reconciliation_command_skips_phase_9a_rows_younger_than_min_age(): void
    {
        [, $attempt, $txn] = $this->makeStuckFundingAttemptFixture([
            'txn_created_at' => now()->subMinutes(2),
            'txn_updated_at' => now()->subMinutes(2),
            'attempt_created_at' => now()->subMinutes(2),
            'attempt_updated_at' => now()->subMinutes(2),
        ]);

        $mtnClient = Mockery::mock(\App\Domain\MtnMomo\Services\MtnMomoClient::class);
        $mtnClient->shouldNotReceive('getRequestToPayStatus');
        $this->app->instance(\App\Domain\MtnMomo\Services\MtnMomoClient::class, $mtnClient);

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldNotReceive('deposit');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $exitCode = Artisan::call('mtn:reconcile-disbursements', [
            '--min-age' => 15,
        ]);

        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('mtn_momo_transactions', [
            'id' => $txn->id,
            'status' => MtnMomoTransaction::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('minor_family_funding_attempts', [
            'id' => $attempt->id,
            'status' => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
        ]);
    }

    #[Test]
    public function reconciliation_command_reports_unreconciled_when_minor_support_refund_did_not_occur(): void
    {
        [, $transfer, $txn] = $this->makeStuckSupportTransferFixture();

        $mtnClient = Mockery::mock(\App\Domain\MtnMomo\Services\MtnMomoClient::class);
        $mtnClient->shouldReceive('getTransferStatus')
            ->once()
            ->with($txn->mtn_reference_id)
            ->andReturn(['status' => 'FAILED']);
        $this->app->instance(\App\Domain\MtnMomo\Services\MtnMomoClient::class, $mtnClient);

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldReceive('deposit')
            ->once()
            ->andThrow(new RuntimeException('refund failed'));
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $exitCode = Artisan::call('mtn:reconcile-disbursements', [
            '--min-age' => 1,
        ]);
        $output = (string) preg_replace('/\e\[[\d;]*m/', '', Artisan::output());

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('refunded=0', $output);
        $this->assertStringContainsString('unreconciled=1', $output);

        $this->assertDatabaseHas('mtn_momo_transactions', [
            'id' => $txn->id,
            'status' => MtnMomoTransaction::STATUS_FAILED,
        ]);

        $this->assertDatabaseHas('minor_family_support_transfers', [
            'id' => $transfer->id,
            'status' => MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED,
            'failed_reason' => 'wallet_refund_failed',
        ]);
    }

    /**
     * @return array{0: Account, 1: MinorFamilyFundingAttempt, 2: MtnMomoTransaction}
     */
    private function makeStuckFundingAttemptFixture(array $overrides = []): array
    {
        $creator = User::factory()->create();
        $minorOwner = User::factory()->create();
        $creatorAccount = Account::factory()->create([
            'user_uuid' => $creator->uuid,
            'type' => 'personal',
        ]);
        $minorAccount = Account::factory()->create([
            'user_uuid' => $minorOwner->uuid,
            'type' => 'minor',
        ]);

        $link = MinorFamilyFundingLink::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => (string) Str::uuid(),
            'minor_account_uuid' => $minorAccount->uuid,
            'created_by_user_uuid' => $creator->uuid,
            'created_by_account_uuid' => $creatorAccount->uuid,
            'title' => 'Support transport',
            'note' => 'Family support',
            'token' => (string) Str::uuid(),
            'status' => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode' => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount' => '150.00',
            'target_amount' => null,
            'collected_amount' => '0.00',
            'asset_code' => 'SZL',
            'provider_options' => ['mtn_momo'],
            'expires_at' => now()->addDay(),
        ]);

        $attemptId = (string) Str::uuid();
        $txn = MtnMomoTransaction::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $creator->id,
            'idempotency_key' => (string) Str::uuid(),
            'type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
            'amount' => '150.00',
            'currency' => 'SZL',
            'status' => MtnMomoTransaction::STATUS_PENDING,
            'party_msisdn' => '26876123456',
            'mtn_reference_id' => (string) Str::uuid(),
            'created_at' => $overrides['txn_created_at'] ?? now()->subMinutes(20),
            'updated_at' => $overrides['txn_updated_at'] ?? now()->subMinutes(20),
        ]);
        $txn->forceFill([
            'context_type' => MinorFamilyFundingAttempt::class,
            'context_uuid' => $attemptId,
        ])->save();
        DB::table('mtn_momo_transactions')
            ->where('id', $txn->id)
            ->update([
                'created_at' => $overrides['txn_created_at'] ?? now()->subMinutes(20),
                'updated_at' => $overrides['txn_updated_at'] ?? now()->subMinutes(20),
            ]);
        $txn->refresh();

        $attempt = MinorFamilyFundingAttempt::query()->create([
            'id' => $attemptId,
            'tenant_id' => $overrides['attempt_tenant_id'] ?? $link->tenant_id,
            'funding_link_uuid' => $link->id,
            'minor_account_uuid' => $minorAccount->uuid,
            'status' => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
            'sponsor_name' => 'Auntie',
            'sponsor_msisdn' => '26876123456',
            'amount' => '150.00',
            'asset_code' => 'SZL',
            'provider_name' => 'mtn_momo',
            'provider_reference_id' => $txn->mtn_reference_id,
            'mtn_momo_transaction_id' => $txn->id,
            'dedupe_hash' => hash('sha256', (string) Str::uuid()),
            'created_at' => $overrides['attempt_created_at'] ?? now()->subMinutes(20),
            'updated_at' => $overrides['attempt_updated_at'] ?? now()->subMinutes(20),
        ]);

        return [$minorAccount, $attempt, $txn];
    }

    /**
     * @return array{0: Account, 1: MinorFamilySupportTransfer, 2: MtnMomoTransaction}
     */
    private function makeStuckSupportTransferFixture(): array
    {
        $actor = User::factory()->create(['kyc_status' => 'approved']);
        $minorOwner = User::factory()->create();

        $sourceAccount = Account::factory()->create([
            'user_uuid' => $actor->uuid,
            'type' => 'personal',
        ]);

        $minorAccount = Account::factory()->create([
            'user_uuid' => $minorOwner->uuid,
            'type' => 'minor',
        ]);

        $transferId = (string) Str::uuid();
        $txn = MtnMomoTransaction::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $actor->id,
            'idempotency_key' => (string) Str::uuid(),
            'type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
            'amount' => '250.00',
            'currency' => 'SZL',
            'status' => MtnMomoTransaction::STATUS_PENDING,
            'party_msisdn' => '26876999000',
            'mtn_reference_id' => (string) Str::uuid(),
            'wallet_debited_at' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);
        $txn->forceFill([
            'context_type' => MinorFamilySupportTransfer::class,
            'context_uuid' => $transferId,
        ])->save();

        $transfer = MinorFamilySupportTransfer::query()->create([
            'id' => $transferId,
            'tenant_id' => (string) Str::uuid(),
            'minor_account_uuid' => $minorAccount->uuid,
            'actor_user_uuid' => $actor->uuid,
            'source_account_uuid' => $sourceAccount->uuid,
            'status' => MinorFamilySupportTransfer::STATUS_PENDING_PROVIDER,
            'provider_name' => 'mtn_momo',
            'recipient_name' => 'Gogo Dlamini',
            'recipient_msisdn' => '26876999000',
            'amount' => '250.00',
            'asset_code' => 'SZL',
            'note' => 'Support transfer',
            'provider_reference_id' => $txn->mtn_reference_id,
            'mtn_momo_transaction_id' => $txn->id,
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);

        return [$sourceAccount, $transfer, $txn];
    }

    private function ensurePhase9Schema(): void
    {
        if (! Schema::hasTable('minor_family_funding_links')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_04_23_100000_create_minor_family_funding_links_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_family_funding_attempts')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_04_23_100100_create_minor_family_funding_attempts_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_family_support_transfers')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_04_23_100200_create_minor_family_support_transfers_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasColumns('mtn_momo_transactions', ['context_type', 'context_uuid'])) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_04_23_100300_add_minor_family_context_to_mtn_momo_transactions_table.php',
                '--force' => true,
            ]);
        }
    }
}
