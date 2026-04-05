<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\InternalP2pTransferService;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

/**
 * Phase 19 — Performance & Load Testing for MaphaPay compat money flows.
 *
 * Minimum scenarios from the replacement plan:
 *  1. Send-money: idempotency, no deadlock
 *  2. Balance consistency after verification
 *
 * MTN scenarios require the full k6 staging environment. See docs/phase-19-load-test-k6.md.
 *
 * Note: PHP single-threaded test environments cannot exercise true network-level concurrency.
 * These tests validate concurrency-safe behaviors (idempotency, atomic operations) sequentially.
 * For true concurrent HTTP load, deploy the k6 scripts from docs/phase-19-load-test-k6.md.
 *
 * P95 latency SLA targets (plan line 2041):
 *   GET  /api/wallet-linking             < 200 ms
 *   POST /api/send-money/store           < 500 ms
 *   GET  /api/wallet-transfer/mtn-momo/status/{key}  < 300 ms
 *   GET  /api/social-money/threads       < 400 ms
 */
#[Large]
class Phase19LoadTest extends ControllerTestCase
{
    private const ASSET_CODE = 'SZL';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Asset::firstOrCreate(
            ['code' => self::ASSET_CODE],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Scenario 1 — Send-money idempotency
    // -------------------------------------------------------------------------

    #[Test]
    public function send_money_same_idempotency_key_replays_without_creating_new_transaction(): void
    {
        $sender = $this->createUserWithAccount('sender1@example.com', 5_000_000);
        $recipient = $this->createUserWithAccount('recipient1@example.com', 0);

        config([
            'maphapay_migration.enable_send_money'   => true,
            'maphapay_migration.enable_verification' => false,
        ]);

        $transferStub = $this->makeTransferStub();
        $this->app->instance(InternalP2pTransferService::class, $transferStub);

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        $idemKey = (string) Str::uuid();
        $payload = [
            'user'              => $recipient->email,
            'amount'            => '1.00',
            'verification_type' => 'sms',
        ];

        $first = $this->postJson('/api/send-money/store', $payload, ['X-Idempotency-Key' => $idemKey]);
        $first->assertOk();

        $second = $this->postJson('/api/send-money/store', $payload, ['X-Idempotency-Key' => $idemKey]);
        $second->assertOk();

        $this->assertSame($first->json(), $second->json(), 'Same idempotency key should replay identical response');

        $count = AuthorizedTransaction::query()
            ->where('user_id', $sender->id)
            ->where('remark', AuthorizedTransaction::REMARK_SEND_MONEY)
            ->count();

        $this->assertSame(1, $count, 'Exactly 1 authorized transaction for repeated idempotency key');
    }

    #[Test]
    public function send_money_unique_idempotency_keys_create_separate_transactions(): void
    {
        $sender = $this->createUserWithAccount('sender2@example.com', 5_000_000);
        $recipient = $this->createUserWithAccount('recipient2@example.com', 0);

        config([
            'maphapay_migration.enable_send_money'   => true,
            'maphapay_migration.enable_verification' => false,
        ]);

        $walletStub = $this->makeWalletStub();
        $this->app->instance(WalletOperationsService::class, $walletStub);

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/send-money/store', [
                'user'              => $recipient->email,
                'amount'            => '0.50',
                'verification_type' => 'sms',
            ], ['X-Idempotency-Key' => (string) Str::uuid()]);
        }

        $count = AuthorizedTransaction::query()
            ->where('user_id', $sender->id)
            ->where('remark', AuthorizedTransaction::REMARK_SEND_MONEY)
            ->count();

        $this->assertSame(10, $count, 'Exactly 10 transactions for 10 unique idempotency keys');
    }

    #[Test]
    public function send_money_endpoint_does_not_deadlock_authorized_transactions_table(): void
    {
        $sender = $this->createUserWithAccount('sender3@example.com', 5_000_000);
        $recipient = $this->createUserWithAccount('recipient3@example.com', 0);

        config([
            'maphapay_migration.enable_send_money'   => true,
            'maphapay_migration.enable_verification' => false,
        ]);

        $walletStub = $this->makeWalletStub();
        $this->app->instance(WalletOperationsService::class, $walletStub);

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/send-money/store', [
                'user'              => $recipient->email,
                'amount'            => '1.00',
                'verification_type' => 'sms',
            ]);
        }

        $this->assertGreaterThan(
            0,
            AuthorizedTransaction::query()->count(),
            'authorized_transactions table should have rows — no deadlock',
        );
    }

    // -------------------------------------------------------------------------
    // Scenario 4 — Balance consistency after verification
    // -------------------------------------------------------------------------

    #[Test]
    public function balance_read_reflects_written_transfers_after_verification(): void
    {
        $sender = $this->createUserWithAccount('sender4@example.com', 5_000_00);
        $recipient = $this->createUserWithAccount('recipient4@example.com', 0);
        $senderAccount = Account::query()->where('user_uuid', $sender->uuid)->firstOrFail();

        config([
            'maphapay_migration.enable_send_money'   => true,
            'maphapay_migration.enable_verification' => true,
        ]);

        $walletStub = $this->makeWalletStub();
        $this->app->instance(WalletOperationsService::class, $walletStub);

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        $store = $this->postJson('/api/send-money/store', [
            'user'              => $recipient->email,
            'amount'            => '10.00',
            'verification_type' => 'sms',
        ]);
        $store->assertOk();
        $trx = (string) $store->json('data.trx');

        $txn = AuthorizedTransaction::query()->where('trx', $trx)->firstOrFail();
        $txn->update([
            'otp_hash'       => bcrypt('123456'),
            'otp_sent_at'    => now(),
            'otp_expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/verification-process/verify/otp', [
            'trx'    => $trx,
            'otp'    => '123456',
            'remark' => 'send_money',
        ])->assertOk();

        $balance = AccountBalance::query()
            ->where('account_uuid', $senderAccount->uuid)
            ->where('asset_code', self::ASSET_CODE)
            ->first();

        $this->assertNotNull($balance);
        $expected = 5_000_00 - 1000;
        $this->assertSame($expected, $balance->balance, 'Sender balance should reflect the transfer after verification');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createUserWithAccount(string $email, int $balanceMinor): User
    {
        $user = User::factory()->create([
            'kyc_status'     => 'approved',
            'kyc_expires_at' => null,
        ]);

        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        AccountBalance::factory()
            ->forAccount($account)
            ->forAsset(self::ASSET_CODE)
            ->withBalance($balanceMinor)
            ->create();

        return $user;
    }

    private function makeWalletStub(): WalletOperationsService&\PHPUnit\Framework\MockObject\MockObject
    {
        $stub = $this->createMock(WalletOperationsService::class);
        $stub->method('withdraw')->willReturn('stub-withdraw-' . Str::random(8));
        $stub->method('deposit')->willReturn('stub-deposit-' . Str::random(8));
        $stub->method('transfer')->willReturnCallback(
            function (string $fromWalletId, string $toWalletId, string $assetCode, string $amount, string $reference, array $meta): string {
                $delta = (int) $amount;
                AccountBalance::query()
                    ->where('account_uuid', $fromWalletId)
                    ->where('asset_code', $assetCode)
                    ->decrement('balance', $delta);
                AccountBalance::query()
                    ->where('account_uuid', $toWalletId)
                    ->where('asset_code', $assetCode)
                    ->increment('balance', $delta);

                return 'stub-transfer-' . $reference;
            },
        );

        return $stub;
    }

    private function makeTransferStub(): InternalP2pTransferService&\PHPUnit\Framework\MockObject\MockObject
    {
        $stub = $this->createMock(InternalP2pTransferService::class);
        $stub->method('execute')->willReturnCallback(
            function (string $fromAccountUuid, string $toAccountUuid, string $amount, string $assetCode, string $reference): array {
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
                    'amount'     => $amount,
                    'asset_code' => $assetCode,
                    'reference'  => 'stub-transfer-' . $reference,
                ];
            },
        );

        return $stub;
    }
}
