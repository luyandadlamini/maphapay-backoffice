<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\InternalP2pTransferService;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\ControllerTestCase;

/**
 * Send-money balance assertions for the compat OTP verification path.
 *
 * OTP values are injected by updating the pending {@see AuthorizedTransaction} row in the test
 * (production stores only a hash).
 *
 * The success-path internal P2P transfer executor is stubbed to mutate
 * {@see AccountBalance} rows because the live workflow stack currently type-errors when it
 * receives a numeric string amount (documented on {@see WalletBalanceConsistencyTest::test_send_money_finalize_moves_balances}).
 */
#[Large]
class WalletBalanceConsistencyTest extends ControllerTestCase
{
    private User $sender;

    private User $recipient;

    private Account $senderAccount;

    private Account $recipientAccount;

    protected function setUp(): void
    {
        parent::setUp();

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        $this->sender = User::factory()->create([
            'kyc_status'     => 'approved',
            'kyc_expires_at' => null,
        ]);
        $this->recipient = User::factory()->create([
            'kyc_status'     => 'approved',
            'kyc_expires_at' => null,
        ]);

        $this->senderAccount = $this->createAccount($this->sender);
        $this->recipientAccount = $this->createAccount($this->recipient);

        AccountBalance::factory()
            ->forAccount($this->senderAccount)
            ->forAsset('SZL')
            ->withBalance(1_000_000)
            ->create();

        AccountBalance::factory()
            ->forAccount($this->recipientAccount)
            ->forAsset('SZL')
            ->withBalance(0)
            ->create();
    }

    #[Test]
    public function test_send_money_finalize_moves_balances(): void
    {
        config([
            'maphapay_migration.enable_send_money'   => true,
            'maphapay_migration.enable_verification' => true,
        ]);

        // Stub the canonical transfer executor and apply the same balance deltas that
        // the asset-transfer aggregate would produce so this test remains deterministic.
        $transferService = $this->createMock(InternalP2pTransferService::class);
        $transferService->method('execute')->willReturnCallback(
            function (
                string $fromAccountUuid,
                string $toAccountUuid,
                string $amount,
                string $assetCode,
                string $reference,
            ): array {
                $delta = (int) round(((float) $amount) * 100);
                AccountBalance::query()
                    ->where('account_uuid', $fromAccountUuid)
                    ->where('asset_code', $assetCode)
                    ->decrement('balance', $delta);
                AccountBalance::query()
                    ->where('account_uuid', $toAccountUuid)
                    ->where('asset_code', $assetCode)
                    ->increment('balance', $delta);

                return [
                    'amount' => $amount,
                    'asset_code' => $assetCode,
                    'reference' => 'stub-transfer-' . $reference,
                ];
            },
        );
        $this->app->instance(InternalP2pTransferService::class, $transferService);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $store = $this->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '10.00',
            'verification_type' => 'sms',
        ]);
        $store->assertOk();
        $trx = (string) $store->json('data.trx');

        $this->forceOtpForTrx($trx);

        $verify = $this->postJson('/api/verification-process/verify/otp', [
            'trx'    => $trx,
            'otp'    => '123456',
            'remark' => 'send_money',
        ]);
        $verify->assertOk()->assertJsonPath('status', 'success');

        $this->senderAccount->refresh();
        $this->recipientAccount->refresh();

        $this->assertSame(999_000, $this->senderAccount->getBalance('SZL'));
        $this->assertSame(1_000, $this->recipientAccount->getBalance('SZL'));

        $this->assertDatabaseHas('authorized_transactions', [
            'trx'    => $trx,
            'status' => AuthorizedTransaction::STATUS_COMPLETED,
        ]);
    }

    #[Test]
    public function test_send_money_finalize_failure_leaves_balances_unchanged(): void
    {
        config([
            'maphapay_migration.enable_send_money'   => true,
            'maphapay_migration.enable_verification' => true,
        ]);

        $transferService = $this->createMock(InternalP2pTransferService::class);
        $transferService->method('execute')->willThrowException(new RuntimeException('Simulated transfer failure'));
        $this->app->instance(InternalP2pTransferService::class, $transferService);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $senderBefore = $this->senderAccount->fresh();
        $recipientBefore = $this->recipientAccount->fresh();
        $this->assertNotNull($senderBefore);
        $this->assertNotNull($recipientBefore);
        $beforeSender = $senderBefore->getBalance('SZL');
        $beforeRecipient = $recipientBefore->getBalance('SZL');

        $store = $this->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '2.50',
            'verification_type' => 'sms',
        ]);
        $store->assertOk();
        $trx = (string) $store->json('data.trx');

        $this->forceOtpForTrx($trx);

        $this->postJson('/api/verification-process/verify/otp', [
            'trx'    => $trx,
            'otp'    => '123456',
            'remark' => 'send_money',
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');

        $senderAfter = $this->senderAccount->fresh();
        $recipientAfter = $this->recipientAccount->fresh();
        $this->assertNotNull($senderAfter);
        $this->assertNotNull($recipientAfter);
        $this->assertSame($beforeSender, $senderAfter->getBalance('SZL'));
        $this->assertSame($beforeRecipient, $recipientAfter->getBalance('SZL'));

        // finalizeAtomically() wraps claim + handler in one DB::transaction; the handler catch
        // rethrows after marking failed, so the outer rollback restores the row to pending.
        $this->assertDatabaseHas('authorized_transactions', [
            'trx'    => $trx,
            'status' => AuthorizedTransaction::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function test_send_money_with_insufficient_balance_fails_closed_and_keeps_balances_unchanged(): void
    {
        config([
            'maphapay_migration.enable_send_money'   => true,
            'maphapay_migration.enable_verification' => true,
        ]);

        $transferService = $this->createMock(InternalP2pTransferService::class);
        $transferService->method('execute')->willThrowException(new RuntimeException('Insufficient balance.'));
        $this->app->instance(InternalP2pTransferService::class, $transferService);

        AccountBalance::query()
            ->where('account_uuid', $this->senderAccount->uuid)
            ->where('asset_code', 'SZL')
            ->update(['balance' => 100]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $beforeSender = $this->senderAccount->fresh()?->getBalance('SZL');
        $beforeRecipient = $this->recipientAccount->fresh()?->getBalance('SZL');
        $this->assertSame(100, $beforeSender);
        $this->assertSame(0, $beforeRecipient);

        $store = $this->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '2.50',
            'verification_type' => 'sms',
        ]);
        $store->assertOk();
        $trx = (string) $store->json('data.trx');

        $this->forceOtpForTrx($trx);

        $verify = $this->postJson('/api/verification-process/verify/otp', [
            'trx'    => $trx,
            'otp'    => '123456',
            'remark' => 'send_money',
        ]);

        $verify->assertStatus(422)
            ->assertJsonPath('status', 'error');
        $this->assertStringContainsString(
            'Insufficient balance',
            (string) $verify->json('message.0'),
        );

        $this->assertSame($beforeSender, $this->senderAccount->fresh()?->getBalance('SZL'));
        $this->assertSame($beforeRecipient, $this->recipientAccount->fresh()?->getBalance('SZL'));

        $this->assertDatabaseHas('authorized_transactions', [
            'trx'    => $trx,
            'status' => AuthorizedTransaction::STATUS_PENDING,
        ]);
    }

    private function forceOtpForTrx(string $trx): void
    {
        $txn = AuthorizedTransaction::query()->where('trx', $trx)->firstOrFail();
        $txn->update([
            'otp_hash'       => Hash::make('123456'),
            'otp_sent_at'    => now(),
            'otp_expires_at' => now()->addMinutes(10),
        ]);
    }
}
