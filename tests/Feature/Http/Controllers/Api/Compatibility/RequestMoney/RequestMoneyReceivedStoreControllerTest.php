<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\RequestMoney;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Large;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

#[Large]
class RequestMoneyReceivedStoreControllerTest extends ControllerTestCase
{
    private User $requester;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requester = User::factory()->create(['kyc_status' => 'approved']);
        $this->recipient = User::factory()->create(['kyc_status' => 'approved']);
        $this->createAccount($this->requester);
        $this->createAccount($this->recipient);
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postReceivedStore(string $moneyRequestId, array $payload = [], ?string $idempotencyKey = null): TestResponse
    {
        $headers = [
            'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid(),
        ];

        return $this->withHeaders($headers)
            ->postJson("/api/request-money/received-store/{$moneyRequestId}", $payload);
    }

    #[Test]
    public function test_received_store_initiates_accept_flow_for_pending_request(): void
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
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => 'TRX-ORIGINAL1',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $response = $this->postReceivedStore($moneyRequestId, [
            'verification_type' => 'sms',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'remark' => 'request_money_received',
            ])
            ->assertJsonPath('data.next_step', 'otp')
            ->assertJsonStructure([
                'data' => [
                    'trx',
                ],
            ]);

        $trx = $response->json('data.trx');
        $this->assertIsString($trx);

        $this->assertDatabaseHas('authorized_transactions', [
            'trx'     => $trx,
            'remark'  => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            'user_id' => $this->recipient->id,
        ]);
    }

    #[Test]
    public function test_received_store_returns_error_when_request_not_pending(): void
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
            'status'            => MoneyRequest::STATUS_AWAITING_OTP,
            'trx'               => null,
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postReceivedStore($moneyRequestId)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    #[Test]
    public function test_received_store_returns_error_when_user_is_not_recipient(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $other = User::factory()->create(['kyc_status' => 'approved']);
        $this->createAccount($other);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '10.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($other, ['read', 'write', 'delete']);

        $this->postReceivedStore($moneyRequestId)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    #[Test]
    public function test_received_store_returns_error_when_request_already_fulfilled(): void
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

        $this->postReceivedStore($moneyRequestId)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    #[Test]
    public function test_received_store_returns_error_when_recipient_account_is_frozen(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $frozenRecipient = User::factory()->create(['kyc_status' => 'approved']);
        $account = $this->createAccount($frozenRecipient);
        Account::query()->where('uuid', $account->uuid)->update(['frozen' => true]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $frozenRecipient->id,
            'amount'            => '10.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($frozenRecipient, ['read', 'write', 'delete']);

        $this->postReceivedStore($moneyRequestId)
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    #[Test]
    public function test_received_store_embeds_idempotency_key_in_payload(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '12.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => 'TRX-IDEM-RECV',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000000020',
        ])->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'pin',
        ]);

        $response->assertOk();

        $trx = $response->json('data.trx');
        /** @var AuthorizedTransaction $txn */
        $txn = AuthorizedTransaction::where('trx', $trx)->firstOrFail();

        $this->assertSame('00000000-0000-0000-0000-000000000020', $txn->payload['_idempotency_key'] ?? null);
    }

    #[Test]
    public function test_received_store_replays_existing_pending_authorization_for_same_idempotency_key(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '12.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => 'TRX-IDEM-RECV-REPLAY',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $headers = ['Idempotency-Key' => '00000000-0000-0000-0000-000000000021'];

        $first = $this->withHeaders($headers)->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'pin',
        ]);

        $first->assertOk();

        $second = $this->withHeaders($headers)->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'pin',
        ]);

        $second->assertOk()
            ->assertJsonPath('data.trx', $first->json('data.trx'))
            ->assertJsonPath('data.next_step', 'otp');

        $this->assertSame(
            1,
            AuthorizedTransaction::query()
                ->where('remark', AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED)
                ->where('user_id', $this->recipient->id)
                ->where('payload->money_request_id', $moneyRequestId)
                ->count(),
        );
    }

    #[Test]
    public function test_received_store_replays_existing_pending_authorization_when_client_hint_changes_but_policy_stays_otp(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $this->recipient->update(['transaction_pin' => null]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '12.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => 'TRX-IDEM-RECV-POLICY-REPLAY',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $headers = ['Idempotency-Key' => '00000000-0000-0000-0000-000000000031'];

        $first = $this->withHeaders($headers)->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'sms',
        ]);

        $first->assertOk()
            ->assertJsonPath('data.next_step', 'otp');

        Cache::flush();

        $second = $this->withHeaders($headers)->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'pin',
        ]);

        $second->assertOk()
            ->assertJsonPath('data.trx', $first->json('data.trx'))
            ->assertJsonPath('data.next_step', 'otp');

        $this->assertSame(
            1,
            AuthorizedTransaction::query()
                ->where('remark', AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED)
                ->where('user_id', $this->recipient->id)
                ->where('payload->money_request_id', $moneyRequestId)
                ->count(),
        );
    }

    #[Test]
    public function test_received_store_allows_missing_idempotency_key_for_backward_compatibility(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '12.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => 'TRX-IDEM-RECV',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'pin',
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.next_step', 'otp');
    }

    #[Test]
    public function test_received_store_uses_pin_policy_when_recipient_has_a_transaction_pin_even_if_client_requests_sms(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $this->recipient->update(['transaction_pin' => '1234', 'transaction_pin_enabled' => true]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '12.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => 'TRX-IDEM-RECV-PIN',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $response = $this->postReceivedStore($moneyRequestId, [
            'verification_type' => 'sms',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'pin');

        $trx = (string) $response->json('data.trx');
        /** @var AuthorizedTransaction $txn */
        $txn = AuthorizedTransaction::query()->where('trx', $trx)->firstOrFail();

        $this->assertSame(AuthorizedTransaction::VERIFICATION_PIN, $txn->verification_type);
        $this->assertSame(
            AuthorizedTransaction::VERIFICATION_PIN,
            $txn->payload['_verification_policy']['verification_type'] ?? null,
        );
        $this->assertSame('sms', $txn->payload['_verification_policy']['client_hint'] ?? null);
    }

    #[Test]
    public function test_received_store_uses_otp_policy_when_recipient_has_no_pin_even_if_client_requests_pin(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $this->recipient->update(['transaction_pin' => null]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '12.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => 'TRX-IDEM-RECV-OTP',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $response = $this->postReceivedStore($moneyRequestId, [
            'verification_type' => 'pin',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.next_step', 'otp');

        $trx = (string) $response->json('data.trx');
        /** @var AuthorizedTransaction $txn */
        $txn = AuthorizedTransaction::query()->where('trx', $trx)->firstOrFail();

        $this->assertSame(AuthorizedTransaction::VERIFICATION_OTP, $txn->verification_type);
        $this->assertSame(
            AuthorizedTransaction::VERIFICATION_OTP,
            $txn->payload['_verification_policy']['verification_type'] ?? null,
        );
        $this->assertSame('pin', $txn->payload['_verification_policy']['client_hint'] ?? null);
    }

    #[Test]
    public function test_received_store_reuses_existing_pending_authorization_after_http_idempotency_cache_loss(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '12.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => 'TRX-IDEM-RECV',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $existingTxn = AuthorizedTransaction::query()->create([
            'user_id' => $this->recipient->id,
            'remark'  => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
            'trx'     => 'TRX-EXISTING-RECV',
            'payload' => [
                'money_request_id'  => $moneyRequestId,
                'requester_user_id' => $this->requester->id,
                'amount'            => '12.00',
                'asset_code'        => 'SZL',
                'from_account_uuid' => Account::query()->where('user_uuid', $this->recipient->uuid)->value('uuid'),
                'to_account_uuid'   => Account::query()->where('user_uuid', $this->requester->uuid)->value('uuid'),
                '_idempotency_key'  => '00000000-0000-0000-0000-000000000021',
            ],
            'status'            => AuthorizedTransaction::STATUS_PENDING,
            'verification_type' => AuthorizedTransaction::VERIFICATION_OTP,
            'expires_at'        => now()->addHour(),
        ]);

        $response = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000000021',
        ])->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'sms',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.trx', $existingTxn->trx);

        $this->assertSame(
            1,
            AuthorizedTransaction::query()
                ->where('remark', AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED)
                ->where('user_id', $this->recipient->id)
                ->where('payload->money_request_id', $moneyRequestId)
                ->count(),
        );
    }

    #[Test]
    public function test_received_store_rejects_different_idempotency_key_when_pending_authorization_already_exists(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '12.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => 'TRX-IDEM-RECV-CONFLICT',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $first = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000000041',
        ])->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'sms',
        ]);

        $first->assertOk()
            ->assertJsonPath('data.next_step', 'otp');

        $second = $this->withHeaders([
            'Idempotency-Key' => '00000000-0000-0000-0000-000000000042',
        ])->postJson("/api/request-money/received-store/{$moneyRequestId}", [
            'verification_type' => 'sms',
        ]);

        $second->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message.0', 'A payment authorization for this money request is already in progress.');

        $this->assertSame(
            1,
            AuthorizedTransaction::query()
                ->where('remark', AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED)
                ->where('user_id', $this->recipient->id)
                ->where('payload->money_request_id', $moneyRequestId)
                ->count(),
        );
    }

    #[Test]
    public function test_received_store_rejects_verification_type_none(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '12.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => 'TRX-IDEM-RECV',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postReceivedStore($moneyRequestId, [
            'verification_type' => 'none',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['verification_type']);
    }

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config([
            'maphapay_migration.enable_request_money' => false,
        ]);

        $moneyRequestId = (string) Str::uuid();

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postReceivedStore($moneyRequestId)
            ->assertNotFound();
    }

    #[Test]
    public function test_received_store_route_not_registered_when_accept_flag_disabled_even_if_parent_flag_enabled(): void
    {
        config([
            'maphapay_migration.enable_request_money'        => true,
            'maphapay_migration.enable_request_money_accept' => false,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id'                => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount'            => '12.00',
            'asset_code'        => 'SZL',
            'note'              => null,
            'status'            => MoneyRequest::STATUS_PENDING,
            'trx'               => null,
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postReceivedStore($moneyRequestId)
            ->assertNotFound();
    }
}
