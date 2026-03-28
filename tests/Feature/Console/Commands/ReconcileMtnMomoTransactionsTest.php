<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\MtnMomo\Services\MtnMomoClient;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Reconciliation cron for pending MTN MoMo disbursements.
 *
 * #[Large] — first-run SQLite migration set can exceed the default 10 s PHPUnit limit.
 */
#[Large]
final class ReconcileMtnMomoTransactionsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function makePendingDisbursement(int $userId, array $overrides = []): MtnMomoTransaction
    {
        $id = (string) Str::uuid();
        $referenceId = (string) Str::uuid();

        return MtnMomoTransaction::query()->create(array_merge([
            'id'                 => $id,
            'user_id'            => $userId,
            'idempotency_key'    => Str::uuid(),
            'type'               => MtnMomoTransaction::TYPE_DISBURSEMENT,
            'amount'             => '50.00',
            'currency'           => 'SZL',
            'status'             => MtnMomoTransaction::STATUS_PENDING,
            'party_msisdn'       => '26876123456',
            'mtn_reference_id'   => $referenceId,
            'note'               => null,
            'wallet_debited_at'  => now()->subMinutes(30),
            'wallet_refunded_at' => null,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    #[Test]
    public function test_skips_rows_younger_than_min_age(): void
    {
        $user = User::factory()->create();

        $this->makePendingDisbursement($user->id, [
            'wallet_debited_at' => now()->subMinutes(5), // too fresh
            'created_at'        => now()->subMinutes(5),
        ]);

        $mtnClient = Mockery::mock(MtnMomoClient::class);
        $mtnClient->shouldNotReceive('getTransferStatus');
        $this->app->instance(MtnMomoClient::class, $mtnClient);

        $this->assertSame(0, Artisan::call('mtn:reconcile-disbursements', ['--min-age' => 15]));
    }

    #[Test]
    public function test_marks_successful_when_mtn_reports_successful(): void
    {
        $user = User::factory()->create();
        $txn = $this->makePendingDisbursement($user->id);

        $mtnClient = Mockery::mock(MtnMomoClient::class);
        $mtnClient->shouldReceive('getTransferStatus')
            ->once()
            ->with($txn->mtn_reference_id)
            ->andReturn(['status' => 'SUCCESSFUL']);
        $this->app->instance(MtnMomoClient::class, $mtnClient);

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldNotReceive('deposit');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $this->assertSame(0, Artisan::call('mtn:reconcile-disbursements'));

        $fresh = $txn->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(MtnMomoTransaction::STATUS_SUCCESSFUL, $fresh->status);
        $this->assertNull($fresh->wallet_refunded_at);
    }

    #[Test]
    public function test_refunds_wallet_and_marks_failed_when_mtn_reports_failed(): void
    {
        $user = User::factory()->create();
        Account::factory()->create(['user_uuid' => $user->uuid]);
        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );
        $txn = $this->makePendingDisbursement($user->id);

        $mtnClient = Mockery::mock(MtnMomoClient::class);
        $mtnClient->shouldReceive('getTransferStatus')
            ->once()
            ->andReturn(['status' => 'FAILED']);
        $this->app->instance(MtnMomoClient::class, $mtnClient);

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldReceive('deposit')->once();
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $this->assertSame(0, Artisan::call('mtn:reconcile-disbursements'));

        $fresh = $txn->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(MtnMomoTransaction::STATUS_FAILED, $fresh->status);
        $this->assertNotNull($fresh->wallet_refunded_at);
    }

    #[Test]
    public function test_does_not_double_refund_when_row_was_settled_by_callback(): void
    {
        $user = User::factory()->create();
        $txn = $this->makePendingDisbursement($user->id);

        // Simulate callback settling the row between the ID scan and lockForUpdate.
        $mtnClient = Mockery::mock(MtnMomoClient::class);
        $mtnClient->shouldNotReceive('getTransferStatus');

        // Manually flip to successful before command processes it
        $txn->update(['status' => MtnMomoTransaction::STATUS_SUCCESSFUL]);

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldNotReceive('deposit');

        $this->app->instance(MtnMomoClient::class, $mtnClient);
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $this->assertSame(0, Artisan::call('mtn:reconcile-disbursements'));
    }

    #[Test]
    public function test_leaves_still_pending_mtn_rows_unchanged(): void
    {
        $user = User::factory()->create();
        $txn = $this->makePendingDisbursement($user->id);

        $mtnClient = Mockery::mock(MtnMomoClient::class);
        $mtnClient->shouldReceive('getTransferStatus')
            ->once()
            ->andReturn(['status' => 'PENDING']);
        $this->app->instance(MtnMomoClient::class, $mtnClient);

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldNotReceive('deposit');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $this->assertSame(0, Artisan::call('mtn:reconcile-disbursements'));

        // status stays pending
        $fresh = $txn->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(MtnMomoTransaction::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->wallet_refunded_at);
    }

    #[Test]
    public function test_skips_non_disbursement_types(): void
    {
        $user = User::factory()->create();

        $this->makePendingDisbursement($user->id, [
            'type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
        ]);

        $mtnClient = Mockery::mock(MtnMomoClient::class);
        $mtnClient->shouldNotReceive('getTransferStatus');
        $this->app->instance(MtnMomoClient::class, $mtnClient);

        $this->assertSame(0, Artisan::call('mtn:reconcile-disbursements'));
    }

    #[Test]
    public function test_logs_critical_and_marks_failed_when_refund_throws(): void
    {
        Log::spy();

        $user = User::factory()->create();
        Account::factory()->create(['user_uuid' => $user->uuid]);
        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );
        $txn = $this->makePendingDisbursement($user->id);

        $mtnClient = Mockery::mock(MtnMomoClient::class);
        $mtnClient->shouldReceive('getTransferStatus')
            ->once()
            ->andReturn(['status' => 'FAILED']);
        $this->app->instance(MtnMomoClient::class, $mtnClient);

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldReceive('deposit')
            ->once()
            ->andThrow(new RuntimeException('wallet broke'));
        $this->app->instance(WalletOperationsService::class, $walletOps);

        // command returns FAILURE (exit code 1) when errors > 0
        $this->assertSame(1, Artisan::call('mtn:reconcile-disbursements'));

        $freshFailed = $txn->fresh();
        $this->assertNotNull($freshFailed);
        $this->assertSame(MtnMomoTransaction::STATUS_FAILED, $freshFailed->status);
        $this->assertNull($freshFailed->wallet_refunded_at);

        Log::getFacadeRoot()
            ->shouldHaveReceived('critical')
            ->once()
            ->with('ReconcileMtnMomoTransactions: refund failed — funds may be lost', Mockery::subset([
                'mtn_momo_transaction_id' => $txn->id,
            ]));
    }

    #[Test]
    public function test_dry_run_performs_no_writes(): void
    {
        $user = User::factory()->create();
        $txn = $this->makePendingDisbursement($user->id);

        $mtnClient = Mockery::mock(MtnMomoClient::class);
        $mtnClient->shouldReceive('getTransferStatus')
            ->once()
            ->andReturn(['status' => 'FAILED']);
        $this->app->instance(MtnMomoClient::class, $mtnClient);

        $walletOps = Mockery::mock(WalletOperationsService::class);
        $walletOps->shouldNotReceive('deposit');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $this->assertSame(0, Artisan::call('mtn:reconcile-disbursements', ['--dry-run' => true]));

        // No status change in dry-run
        $fresh = $txn->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(MtnMomoTransaction::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->wallet_refunded_at);
    }
}
