<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\InternalP2pTransferService;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\ControllerTestCase;

/**
 * Double-spend and terminal-state coverage for MaphaPay compat money-request acceptance.
 *
 * Note: True concurrent HTTP is not exercised here (PHP single-threaded + sqlite :memory:
 * in-process kernel tests). Overlapping initiation is approximated with back-to-back calls.
 *
 * Phase 1 closes the initiation gap by locking the MoneyRequest row and refusing to
 * mint a second pending acceptance authorization for the same request.
 */
#[Large]
class DoubleSpendProtectionTest extends ControllerTestCase
{
    private User $requester;

    private User $recipient;

    private Account $requesterAccount;

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

        $this->requester = User::factory()->create([
            'kyc_status'     => 'approved',
            'kyc_expires_at' => null,
        ]);
        $this->recipient = User::factory()->create([
            'kyc_status'     => 'approved',
            'kyc_expires_at' => null,
        ]);
        $this->requesterAccount = $this->createAccount($this->requester);
        $this->recipientAccount = $this->createAccount($this->recipient);
    }

    #[Test]
    public function test_fulfilled_money_request_second_accept_returns_422(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '10.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_FULFILLED,
            'trx'               => 'TRX-DONE',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'sms',
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    #[Test]
    public function test_rejected_money_request_accept_returns_422(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '10.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_REJECTED,
            'trx'               => null,
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'sms',
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    #[Test]
    public function test_double_verify_otp_same_request_money_received_trx_transfers_once_second_verify_422(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
            'maphapay_migration.enable_verification'  => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '5.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => null,
        ]);

        $transferService = $this->createMock(InternalP2pTransferService::class);
        $transferService->expects($this->once())
            ->method('execute')
            ->willReturn([
                'amount' => '5.00',
                'asset_code' => 'SZL',
                'reference' => 'stub-transfer-id',
            ]);
        $this->app->instance(InternalP2pTransferService::class, $transferService);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $init = $this->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'sms',
        ]);
        $init->assertOk();
        $trx = (string) $init->json('data.trx');
        $this->assertNotSame('', $trx);

        $this->forceOtpForTrx($trx);

        $payload = [
            'trx'    => $trx,
            'otp'    => '123456',
            'remark' => 'request_money_received',
        ];

        $first = $this->postJson('/api/verification-process/verify/otp', $payload);
        $first->assertOk()->assertJsonPath('status', 'success');

        $second = $this->postJson('/api/verification-process/verify/otp', $payload);
        // verifyOtp() rejects non-pending txns before finalizeAtomically's replay branch runs.
        $second->assertStatus(422)->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('money_requests', [
            'id'     => $moneyRequestId,
            'status' => MoneyRequest::STATUS_FULFILLED,
        ]);

        $this->assertDatabaseHas('authorized_transactions', [
            'trx'    => $trx,
            'status' => AuthorizedTransaction::STATUS_COMPLETED,
        ]);
    }

    #[Test]
    public function test_back_to_back_received_store_without_idempotency_rejects_second_pending_authorization(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '7.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $first = $this->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'sms',
        ]);
        $first->assertOk();

        $second = $this->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'sms',
        ]);
        $second->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message.0', 'A payment authorization for this money request is already in progress.');

        $count = AuthorizedTransaction::query()
            ->where('remark', AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED)
            ->where('user_id', $this->recipient->id)
            ->where('payload->money_request_id', $moneyRequestId)
            ->count();

        $this->assertSame(1, $count);
    }

    #[Test]
    public function test_duplicate_received_store_with_same_idempotency_key_replays_without_extra_row(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '3.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $idem = (string) Str::uuid();
        $body = ['verification_type' => 'sms'];

        $first = $this->postJson(
            "/api/request-money/received-store/{$moneyRequestId}",
            $body,
            ['X-Idempotency-Key' => $idem],
        );
        $first->assertOk();
        $trx = (string) $first->json('data.trx');

        $second = $this->postJson(
            "/api/request-money/received-store/{$moneyRequestId}",
            $body,
            ['X-Idempotency-Key' => $idem],
        );
        $second->assertOk();
        $this->assertSame($trx, (string) $second->json('data.trx'));

        $this->assertSame(1, AuthorizedTransaction::query()
            ->where('remark', AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED)
            ->where('user_id', $this->recipient->id)
            ->where('payload->money_request_id', $moneyRequestId)
            ->count());
    }

    #[Test]
    public function test_request_money_accept_with_insufficient_balance_fails_closed_and_leaves_request_pending(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
            'maphapay_migration.enable_verification'  => true,
        ]);

        $transferService = $this->createMock(InternalP2pTransferService::class);
        $transferService->method('execute')->willThrowException(new RuntimeException('Insufficient balance.'));
        $this->app->instance(InternalP2pTransferService::class, $transferService);

        AccountBalance::factory()
            ->forAccount($this->recipientAccount)
            ->forAsset('SZL')
            ->withBalance(100)
            ->create();
        AccountBalance::factory()
            ->forAccount($this->requesterAccount)
            ->forAsset('SZL')
            ->withBalance(0)
            ->create();

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '5.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $init = $this->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'sms',
        ]);
        $init->assertOk();
        $trx = (string) $init->json('data.trx');
        $this->forceOtpForTrx($trx);

        $beforePayer = AccountBalance::query()
            ->where('account_uuid', $this->recipientAccount->uuid)
            ->where('asset_code', 'SZL')
            ->value('balance');
        $beforeRequester = AccountBalance::query()
            ->where('account_uuid', $this->requesterAccount->uuid)
            ->where('asset_code', 'SZL')
            ->value('balance');

        $verify = $this->postJson('/api/verification-process/verify/otp', [
            'trx'    => $trx,
            'otp'    => '123456',
            'remark' => 'request_money_received',
        ]);

        $verify->assertStatus(422)
            ->assertJsonPath('status', 'error');
        $this->assertStringContainsString(
            'Insufficient balance',
            (string) $verify->json('message.0'),
        );

        $this->assertSame($beforePayer, AccountBalance::query()
            ->where('account_uuid', $this->recipientAccount->uuid)
            ->where('asset_code', 'SZL')
            ->value('balance'));
        $this->assertSame($beforeRequester, AccountBalance::query()
            ->where('account_uuid', $this->requesterAccount->uuid)
            ->where('asset_code', 'SZL')
            ->value('balance'));

        $this->assertDatabaseHas('money_requests', [
            'id'     => $moneyRequestId,
            'status' => MoneyRequest::STATUS_PENDING,
        ]);

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
