<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\Mtn;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Domain\Asset\Models\Asset;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use RuntimeException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorFamilyMtnCallbackIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePhase9Schema();

        DB::table('minor_family_support_transfers')->delete();
        DB::table('minor_family_funding_attempts')->delete();
        DB::table('minor_family_funding_links')->delete();
        DB::table('mtn_momo_transactions')->delete();
        DB::table('minor_family_reconciliation_exceptions')->delete();
        DB::table('mtn_callback_log')->delete();

        config([
            'maphapay_migration.enable_mtn_momo' => true,
            'mtn_momo.verify_callback_token' => false,
        ]);

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
    public function callback_reconciliation_records_missing_tenant_context_exception_with_callback_source(): void
    {
        [, $attempt, $txn] = $this->makeFundingAttemptFixture(
            referenceId: null,
            attemptTenantId: '',
        );

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldNotReceive('deposit');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $this->postJson('/api/mtn/callback', ['status' => 'SUCCESSFUL'], [
            'X-Reference-Id' => $txn->mtn_reference_id,
        ])->assertOk();

        $this->assertDatabaseHas('minor_family_funding_attempts', [
            'id' => $attempt->id,
            'status' => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
            'tenant_id' => '',
        ]);

        $this->assertDatabaseHas('minor_family_reconciliation_exceptions', [
            'mtn_momo_transaction_id' => $txn->id,
            'reason_code' => 'missing_tenant_context',
            'source' => 'callback',
            'status' => 'open',
            'occurrence_count' => 1,
        ]);
    }

    #[Test]
    public function status_poll_reconciliation_records_missing_tenant_context_exception_with_status_poll_source(): void
    {
        [, $attempt, $txn] = $this->makeFundingAttemptFixture(
            referenceId: null,
            attemptTenantId: '',
        );

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldNotReceive('deposit');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $mtnClient = Mockery::mock(\App\Domain\MtnMomo\Services\MtnMomoClient::class);
        $mtnClient->shouldReceive('assertConfigured')->once();
        $mtnClient->shouldReceive('getRequestToPayStatus')
            ->once()
            ->with($txn->mtn_reference_id)
            ->andReturn(['status' => 'SUCCESSFUL']);
        $this->app->instance(\App\Domain\MtnMomo\Services\MtnMomoClient::class, $mtnClient);

        Sanctum::actingAs($txn->user, ['read', 'write', 'delete']);
        $this->getJson('/api/mtn/transaction/' . $txn->mtn_reference_id . '/status')
            ->assertOk()
            ->assertJsonPath('data.transaction.status', MtnMomoTransaction::STATUS_SUCCESSFUL);

        $this->assertDatabaseHas('minor_family_funding_attempts', [
            'id' => $attempt->id,
            'status' => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
            'tenant_id' => '',
        ]);

        $this->assertDatabaseHas('minor_family_reconciliation_exceptions', [
            'mtn_momo_transaction_id' => $txn->id,
            'reason_code' => 'missing_tenant_context',
            'source' => 'status_poll',
            'status' => 'open',
            'occurrence_count' => 1,
        ]);
    }

    #[Test]
    public function unknown_terminal_callback_does_not_consume_dedupe_for_later_legitimate_transaction(): void
    {
        $referenceId = (string) Str::uuid();

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldReceive('deposit')
            ->once()
            ->with(
                Mockery::type('string'),
                'SZL',
                '10000',
                Mockery::type('string'),
                Mockery::type('array'),
            )
            ->andReturn('wallet-credit-after-unknown-callback');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $this->postJson('/api/mtn/callback', ['status' => 'SUCCESSFUL'], [
            'X-Reference-Id' => $referenceId,
        ])->assertOk();

        $this->assertDatabaseMissing('mtn_callback_log', [
            'mtn_reference_id' => $referenceId,
            'terminal_status' => MtnMomoTransaction::STATUS_SUCCESSFUL,
        ]);

        [$minorAccount, $attempt, $txn] = $this->makeFundingAttemptFixture($referenceId);

        $this->postJson('/api/mtn/callback', ['status' => 'SUCCESSFUL'], [
            'X-Reference-Id' => $txn->mtn_reference_id,
        ])->assertOk();

        $this->assertDatabaseHas('minor_family_funding_attempts', [
            'id' => $attempt->id,
            'status' => MinorFamilyFundingAttempt::STATUS_CREDITED,
        ]);

        $this->assertDatabaseHas('mtn_callback_log', [
            'mtn_reference_id' => $referenceId,
            'terminal_status' => MtnMomoTransaction::STATUS_SUCCESSFUL,
        ]);
    }

    #[Test]
    public function successful_public_collection_callback_credits_linked_minor_account_once(): void
    {
        [$minorAccount, $attempt, $txn] = $this->makeFundingAttemptFixture();

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
            ->andReturn('wallet-credit-1');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $this->postJson('/api/mtn/callback', ['status' => 'SUCCESSFUL'], [
            'X-Reference-Id' => $txn->mtn_reference_id,
        ])->assertOk();

        $this->postJson('/api/mtn/callback', ['status' => 'SUCCESSFUL'], [
            'X-Reference-Id' => $txn->mtn_reference_id,
        ])->assertOk();

        $this->assertDatabaseHas('minor_family_funding_attempts', [
            'id' => $attempt->id,
            'status' => MinorFamilyFundingAttempt::STATUS_CREDITED,
        ]);

        $this->assertDatabaseHas('mtn_momo_transactions', [
            'id' => $txn->id,
            'status' => MtnMomoTransaction::STATUS_SUCCESSFUL,
        ]);

        $this->assertNotNull(MtnMomoTransaction::query()->findOrFail($txn->id)->wallet_credited_at);
    }

    #[Test]
    public function successful_public_collection_callback_records_successful_uncredited_when_wallet_credit_fails(): void
    {
        [$minorAccount, $attempt, $txn] = $this->makeFundingAttemptFixture();

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
            ->andThrow(new RuntimeException('wallet credit failed'));
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $this->postJson('/api/mtn/callback', ['status' => 'SUCCESSFUL'], [
            'X-Reference-Id' => $txn->mtn_reference_id,
        ])->assertOk();

        $this->assertDatabaseHas('mtn_momo_transactions', [
            'id' => $txn->id,
            'status' => MtnMomoTransaction::STATUS_SUCCESSFUL,
        ]);

        $this->assertDatabaseHas('minor_family_funding_attempts', [
            'id' => $attempt->id,
            'status' => MinorFamilyFundingAttempt::STATUS_SUCCESSFUL_UNCREDITED,
            'failed_reason' => 'wallet_credit_failed',
        ]);

        $this->assertNull(MtnMomoTransaction::query()->findOrFail($txn->id)->wallet_credited_at);
        $this->assertNull(MinorFamilyFundingAttempt::query()->findOrFail($attempt->id)->wallet_credited_at);
    }

    #[Test]
    public function failed_outbound_support_transfer_callback_refunds_source_account_once(): void
    {
        [$sourceAccount, $transfer, $txn] = $this->makeSupportTransferFixture();

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldReceive('deposit')
            ->once()
            ->with(
                $sourceAccount->uuid,
                'SZL',
                '25000',
                Mockery::type('string'),
                Mockery::type('array'),
            )
            ->andReturn('wallet-refund-1');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $this->postJson('/api/mtn/callback', ['status' => 'FAILED'], [
            'X-Reference-Id' => $txn->mtn_reference_id,
        ])->assertOk();

        $this->postJson('/api/mtn/callback', ['status' => 'FAILED'], [
            'X-Reference-Id' => $txn->mtn_reference_id,
        ])->assertOk();

        $this->assertDatabaseHas('minor_family_support_transfers', [
            'id' => $transfer->id,
            'status' => MinorFamilySupportTransfer::STATUS_FAILED_REFUNDED,
        ]);

        $this->assertNotNull(MinorFamilySupportTransfer::query()->findOrFail($transfer->id)->wallet_refunded_at);
    }

    #[Test]
    public function status_polling_and_callback_converge_to_same_phase_9a_state(): void
    {
        [$sourceAccount, $transfer, $txn, $actor] = $this->makeSupportTransferFixture();

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldReceive('deposit')
            ->once()
            ->with(
                $sourceAccount->uuid,
                'SZL',
                '25000',
                Mockery::type('string'),
                Mockery::type('array'),
            )
            ->andReturn('wallet-refund-2');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $mtnClient = Mockery::mock(\App\Domain\MtnMomo\Services\MtnMomoClient::class);
        $mtnClient->shouldReceive('assertConfigured')->once();
        $mtnClient->shouldReceive('getTransferStatus')
            ->once()
            ->with($txn->mtn_reference_id)
            ->andReturn(['status' => 'FAILED']);
        $this->app->instance(\App\Domain\MtnMomo\Services\MtnMomoClient::class, $mtnClient);

        Sanctum::actingAs($actor, ['read', 'write', 'delete']);
        $this->getJson('/api/mtn/transaction/' . $txn->mtn_reference_id . '/status')
            ->assertOk()
            ->assertJsonPath('data.transaction.status', MtnMomoTransaction::STATUS_FAILED);

        $this->postJson('/api/mtn/callback', ['status' => 'FAILED'], [
            'X-Reference-Id' => $txn->mtn_reference_id,
        ])->assertOk();

        $freshTransfer = MinorFamilySupportTransfer::query()->findOrFail($transfer->id);
        $this->assertSame(MinorFamilySupportTransfer::STATUS_FAILED_REFUNDED, $freshTransfer->status);
        $this->assertNotNull($freshTransfer->wallet_refunded_at);
    }

    #[Test]
    public function status_polling_failed_support_transfer_does_not_500_when_refund_deposit_throws(): void
    {
        [$sourceAccount, $transfer, $txn, $actor] = $this->makeSupportTransferFixture();

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldReceive('deposit')
            ->once()
            ->with(
                $sourceAccount->uuid,
                'SZL',
                '25000',
                Mockery::type('string'),
                Mockery::type('array'),
            )
            ->andThrow(new RuntimeException('wallet refund failed'));
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $mtnClient = Mockery::mock(\App\Domain\MtnMomo\Services\MtnMomoClient::class);
        $mtnClient->shouldReceive('assertConfigured')->once();
        $mtnClient->shouldReceive('getTransferStatus')
            ->once()
            ->with($txn->mtn_reference_id)
            ->andReturn(['status' => 'FAILED']);
        $this->app->instance(\App\Domain\MtnMomo\Services\MtnMomoClient::class, $mtnClient);

        Sanctum::actingAs($actor, ['read', 'write', 'delete']);
        $this->getJson('/api/mtn/transaction/' . $txn->mtn_reference_id . '/status')
            ->assertOk()
            ->assertJsonPath('data.transaction.status', MtnMomoTransaction::STATUS_FAILED);

        $this->assertDatabaseHas('minor_family_support_transfers', [
            'id' => $transfer->id,
            'status' => MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED,
            'failed_reason' => 'wallet_refund_failed',
        ]);

        $this->assertNull(MinorFamilySupportTransfer::query()->findOrFail($transfer->id)->wallet_refunded_at);
        $this->assertNull(MtnMomoTransaction::query()->findOrFail($txn->id)->wallet_refunded_at);
    }

    /**
     * @return array{0: Account, 1: MinorFamilyFundingAttempt, 2: MtnMomoTransaction}
     */
    private function makeFundingAttemptFixture(?string $referenceId = null, ?string $attemptTenantId = null): array
    {
        $creator = User::factory()->create(['kyc_status' => 'approved']);
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
            'title' => 'Support school fees',
            'note' => 'Family support',
            'token' => (string) Str::uuid(),
            'status' => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode' => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount' => '100.00',
            'target_amount' => null,
            'collected_amount' => '0.00',
            'asset_code' => 'SZL',
            'provider_options' => ['mtn_momo'],
            'expires_at' => now()->addDay(),
        ]);

        $txn = MtnMomoTransaction::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $creator->id,
            'idempotency_key' => (string) Str::uuid(),
            'type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
            'amount' => '100.00',
            'currency' => 'SZL',
            'status' => MtnMomoTransaction::STATUS_PENDING,
            'party_msisdn' => '26876123456',
            'mtn_reference_id' => $referenceId ?? (string) Str::uuid(),
        ]);
        $attemptId = (string) Str::uuid();
        $txn->forceFill([
            'context_type' => MinorFamilyFundingAttempt::class,
            'context_uuid' => $attemptId,
        ])->save();

        $attempt = MinorFamilyFundingAttempt::query()->create([
            'id' => $attemptId,
            'tenant_id' => $attemptTenantId ?? $link->tenant_id,
            'funding_link_uuid' => $link->id,
            'minor_account_uuid' => $minorAccount->uuid,
            'status' => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
            'sponsor_name' => 'Auntie',
            'sponsor_msisdn' => '26876123456',
            'amount' => '100.00',
            'asset_code' => 'SZL',
            'provider_name' => 'mtn_momo',
            'provider_reference_id' => $txn->mtn_reference_id,
            'mtn_momo_transaction_id' => $txn->id,
            'dedupe_hash' => hash('sha256', (string) Str::uuid()),
        ]);

        return [$minorAccount, $attempt, $txn];
    }

    /**
     * @return array{0: Account, 1: MinorFamilySupportTransfer, 2: MtnMomoTransaction, 3: User}
     */
    private function makeSupportTransferFixture(): array
    {
        $actor = User::factory()->create(['kyc_status' => 'approved']);
        $minorOwner = User::factory()->create();

        // Create a decoy account so tests verify source-account refund targeting.
        Account::factory()->create([
            'user_uuid' => $actor->uuid,
            'type' => 'personal',
        ]);

        $sourceAccount = Account::factory()->create([
            'user_uuid' => $actor->uuid,
            'type' => 'personal',
        ]);

        $minorAccount = Account::factory()->create([
            'user_uuid' => $minorOwner->uuid,
            'type' => 'minor',
        ]);

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
        ]);
        $transferId = (string) Str::uuid();
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
        ]);

        return [$sourceAccount, $transfer, $txn, $actor];
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

        if (! Schema::hasTable('minor_family_reconciliation_exceptions')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_04_23_100400_create_minor_family_reconciliation_exceptions_table.php',
                '--force' => true,
            ]);
        }
    }
}
