<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\SendMoney;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\InternalP2pTransferService;
use App\Domain\Shared\OperationRecord\OperationRecord;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class SendMoneyStoreControllerTest extends ControllerTestCase
{
    private User $sender;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sender = User::factory()->create(['kyc_status' => 'approved']);
        $this->recipient = User::factory()->create(['kyc_status' => 'approved']);

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );

        Account::factory()->create([
            'user_uuid' => $this->sender->uuid,
            'frozen'    => false,
        ]);

        Account::factory()->create([
            'user_uuid' => $this->recipient->uuid,
            'frozen'    => false,
        ]);
    }

    #[Test]
    public function test_store_returns_success_envelope_with_otp_flow(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '1000.50',
            'verification_type' => 'sms',
            'attestation'       => 'trusted-proof',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'remark' => 'send_money',
            ])
            ->assertJsonPath('data.next_step', 'otp')
            ->assertJsonStructure([
                'data' => [
                    'trx',
                    'code_sent_message',
                ],
            ]);

        $trx = $response->json('data.trx');
        $this->assertIsString($trx);
        $this->assertStringStartsWith('TRX-', $trx);

        $this->assertDatabaseHas('authorized_transactions', [
            'trx'     => $trx,
            'remark'  => AuthorizedTransaction::REMARK_SEND_MONEY,
            'user_id' => $this->sender->id,
        ]);
    }

    #[Test]
    public function test_store_pin_flow_omits_code_sent_message(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
        ]);

        $this->sender->update([
            'transaction_pin'         => bcrypt('1234'),
            'transaction_pin_enabled' => true,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '1.00',
            'verification_type' => 'pin',
            'attestation'       => 'trusted-proof',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'pin')
            ->assertJsonPath('data.code_sent_message', null);
    }

    #[Test]
    public function test_store_rejects_non_string_amount(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user'   => $this->recipient->email,
            'amount' => 10.5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    #[Test]
    public function test_store_embeds_idempotency_key_in_payload(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000000001',
        ])->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '5.00',
            'verification_type' => 'pin',
            'attestation'       => 'trusted-proof',
        ]);

        $response->assertOk();

        $trx = $response->json('data.trx');
        /** @var AuthorizedTransaction $txn */
        $txn = AuthorizedTransaction::where('trx', $trx)->firstOrFail();

        $this->assertSame('00000000-0000-0000-0000-000000000001', $txn->payload['_idempotency_key'] ?? null);
    }

    #[Test]
    public function test_minor_approval_initiation_replays_same_idempotency_key_without_creating_duplicate_rows(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
            'mobile.attestation.enabled'           => false,
        ]);

        $guardianAccount = Account::factory()->create([
            'frozen' => false,
        ]);

        $senderAccount = Account::query()
            ->where('user_uuid', $this->sender->uuid)
            ->firstOrFail();

        $senderAccount->forceFill([
            'type'              => 'minor',
            'permission_level'  => 3,
            'parent_account_id' => $guardianAccount->uuid,
        ])->save();

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $headers = [
            'Idempotency-Key' => 'minor-approval-1',
        ];
        $payload = [
            'user'              => $this->recipient->email,
            'amount'            => '150.00',
            'verification_type' => 'pin',
            'attestation'       => 'trusted-proof',
        ];

        $firstResponse = $this->withHeaders($headers)
            ->postJson('/api/send-money/store', $payload);

        $firstResponse->assertStatus(202)
            ->assertJsonPath('data.status', 'pending_guardian_approval');

        $firstApprovalId = $firstResponse->json('data.approval_id');

        Cache::flush();

        $secondResponse = $this->withHeaders($headers)
            ->postJson('/api/send-money/store', $payload);

        $secondResponse->assertStatus(202)
            ->assertJsonPath('data.status', 'pending_guardian_approval')
            ->assertJsonPath('data.approval_id', $firstApprovalId);

        $this->assertSame(
            1,
            \App\Domain\Account\Models\MinorSpendApproval::query()
                ->where('minor_account_uuid', $senderAccount->uuid)
                ->where('status', 'pending')
                ->count(),
        );
    }

    #[Test]
    public function test_minor_approval_request_applies_trust_step_up_before_creating_pending_approval(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
            'mobile.attestation.enabled'           => false,
        ]);

        $guardianAccount = Account::factory()->create([
            'frozen' => false,
        ]);

        $senderAccount = Account::query()
            ->where('user_uuid', $this->sender->uuid)
            ->firstOrFail();

        $senderAccount->forceFill([
            'type'              => 'minor',
            'permission_level'  => 3,
            'parent_account_id' => $guardianAccount->uuid,
        ])->save();

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000000021',
        ])->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '150.00',
            'verification_type' => 'pin',
        ]);

        $response->assertStatus(428)
            ->assertJsonPath('error.code', 'TRUST_POLICY_STEP_UP');

        $this->assertSame(
            0,
            \App\Domain\Account\Models\MinorSpendApproval::query()
                ->where('minor_account_uuid', $senderAccount->uuid)
                ->count(),
        );
    }

    #[Test]
    public function test_minor_approval_requires_idempotency_key_before_creating_pending_approval(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
            'mobile.attestation.enabled'           => false,
        ]);

        $guardianAccount = Account::factory()->create([
            'frozen' => false,
        ]);

        $senderAccount = Account::query()
            ->where('user_uuid', $this->sender->uuid)
            ->firstOrFail();

        $senderAccount->forceFill([
            'type'              => 'minor',
            'permission_level'  => 3,
            'parent_account_id' => $guardianAccount->uuid,
        ])->save();

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '150.00',
            'verification_type' => 'pin',
            'attestation'       => 'trusted-proof',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message.0', 'Idempotency-Key header is required for guardian approval requests.');

        $this->assertSame(
            0,
            \App\Domain\Account\Models\MinorSpendApproval::query()
                ->where('minor_account_uuid', $senderAccount->uuid)
                ->count(),
        );

        $this->assertNull(
            OperationRecord::query()
                ->where('user_id', $this->sender->id)
                ->where('operation_type', 'minor_spend_approval')
                ->first(),
        );
    }

    #[Test]
    public function test_store_accepts_payment_link_token_as_authoritative_send_money_input(): void
    {
        config([
            'maphapay_migration.enable_send_money' => true,
        ]);

        $moneyRequest = MoneyRequest::query()->create([
            'id'                => (string) Str::uuid(),
            'requester_user_id' => $this->recipient->id,
            'recipient_user_id' => $this->sender->id,
            'amount'            => '150.75',
            'asset_code'        => 'SZL',
            'note'              => 'Invoice 42',
            'status'            => MoneyRequest::STATUS_PENDING,
            'payment_token'     => 'LINKTOKEN123',
            'expires_at'        => now()->addDay(),
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/send-money/store', [
            'payment_link_token' => 'LINKTOKEN123',
            'verification_type'  => 'pin',
            'user'               => 'malicious-override@example.com',
            'amount'             => '999.99',
            'note'               => 'tampered note',
            'attestation'        => 'test-attestation-token',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $trx = (string) $response->json('data.trx');
        /** @var AuthorizedTransaction $txn */
        $txn = AuthorizedTransaction::query()->where('trx', $trx)->firstOrFail();

        $this->assertSame('LINKTOKEN123', $txn->payload['payment_link_token'] ?? null);
        $this->assertSame($moneyRequest->id, $txn->payload['money_request_id'] ?? null);
        $this->assertSame('150.75', $txn->payload['amount'] ?? null);
        $this->assertSame('Invoice 42', $txn->payload['note'] ?? null);
    }

    #[Test]
    public function test_verified_payment_link_send_money_marks_money_request_paid(): void
    {
        config([
            'maphapay_migration.enable_send_money'   => true,
            'maphapay_migration.enable_verification' => true,
        ]);

        $this->sender->update(['transaction_pin' => bcrypt('1234'), 'transaction_pin_enabled' => true]);

        $moneyRequest = MoneyRequest::query()->create([
            'id'                => (string) Str::uuid(),
            'requester_user_id' => $this->recipient->id,
            'recipient_user_id' => $this->sender->id,
            'amount'            => '15.25',
            'asset_code'        => 'SZL',
            'note'              => 'Utilities',
            'status'            => MoneyRequest::STATUS_PENDING,
            'payment_token'     => 'LINKTOKEN456',
            'expires_at'        => now()->addDay(),
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $initResponse = $this->postJson('/api/send-money/store', [
            'payment_link_token' => 'LINKTOKEN456',
            'verification_type'  => 'pin',
            'amount'             => '1.00',
            'attestation'        => 'test-attestation-token',
        ]);

        $initResponse->assertOk()
            ->assertJsonPath('data.next_step', 'pin');

        $trx = (string) $initResponse->json('data.trx');

        $verifyResponse = $this->postJson('/api/verification-process/verify/pin', [
            'trx'    => $trx,
            'pin'    => '1234',
            'remark' => AuthorizedTransaction::REMARK_SEND_MONEY,
        ]);

        $verifyResponse->assertOk()
            ->assertJsonPath('data.amount', '15.25');

        $moneyRequest->refresh();

        $this->assertSame(MoneyRequest::STATUS_FULFILLED, $moneyRequest->status);
        $this->assertNotNull($moneyRequest->paid_at);
    }

    #[Test]
    public function test_second_verify_with_same_idempotency_key_returns_cached_result_without_re_executing(): void
    {
        config([
            'maphapay_migration.enable_send_money'   => true,
            'maphapay_migration.enable_verification' => true,
        ]);

        $this->sender->update(['transaction_pin' => bcrypt('1234'), 'transaction_pin_enabled' => true]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        // Step 1: initiate — key is embedded in the payload.
        $initResponse = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000000002',
        ])->postJson('/api/send-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '7.00',
            'verification_type' => 'pin',
            'attestation'       => 'trusted-proof',
        ]);
        $initResponse->assertOk();
        $trx = (string) $initResponse->json('data.trx');

        // Step 2: pre-seed a completed OperationRecord, simulating the first finalize
        //         having already run (e.g. HTTP-layer cache has expired and user retries).
        $cachedResult = [
            'trx'        => $trx,
            'amount'     => '7.00',
            'asset_code' => 'SZL',
            'reference'  => 'cached-ref-001',
        ];
        OperationRecord::create([
            'id'              => (string) Str::ulid(),
            'user_id'         => $this->sender->id,
            'operation_type'  => AuthorizedTransaction::REMARK_SEND_MONEY,
            'idempotency_key' => '00000000-0000-0000-0000-000000000002',
            'payload_hash'    => 'any-hash',
            'status'          => OperationRecord::STATUS_COMPLETED,
            'result_payload'  => $cachedResult,
        ]);

        // Step 3: wallet service must NOT be called — the guard short-circuits.
        $this->mock(InternalP2pTransferService::class, function ($mock): void {
            $mock->shouldNotReceive('execute');
        });

        // Step 4: verify PIN — domain guard intercepts and returns cached payload.
        $verifyResponse = $this->postJson('/api/verification-process/verify/pin', [
            'trx'    => $trx,
            'pin'    => '1234',
            'remark' => AuthorizedTransaction::REMARK_SEND_MONEY,
        ]);

        $verifyResponse->assertOk()
            ->assertJsonPath('data.trx', $trx)
            ->assertJsonPath('data.asset_code', 'SZL');
    }

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config([
            'maphapay_migration.enable_send_money' => false,
        ]);

        Sanctum::actingAs($this->sender, ['read', 'write', 'delete']);

        $this->postJson('/api/send-money/store', [
            'user'   => $this->recipient->email,
            'amount' => '10.00',
        ])->assertNotFound();
    }
}
