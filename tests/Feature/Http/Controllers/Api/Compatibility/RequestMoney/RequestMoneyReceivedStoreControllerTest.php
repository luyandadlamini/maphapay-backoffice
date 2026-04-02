<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\RequestMoney;

use App\Domain\Account\Models\Account;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

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
    }

    #[Test]
    public function test_received_store_initiates_accept_flow_for_pending_request(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id' => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount' => '10.00',
            'asset_code' => 'SZL',
            'note' => null,
            'status' => MoneyRequest::STATUS_PENDING,
            'trx' => 'TRX-ORIGINAL1',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/request-money/received-store/{$moneyRequestId}", [
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
            'trx' => $trx,
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
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
            'id' => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount' => '10.00',
            'asset_code' => 'SZL',
            'note' => null,
            'status' => MoneyRequest::STATUS_AWAITING_OTP,
            'trx' => null,
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/received-store/{$moneyRequestId}")
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
            'id' => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount' => '10.00',
            'asset_code' => 'SZL',
            'note' => null,
            'status' => MoneyRequest::STATUS_PENDING,
            'trx' => null,
        ]);

        Sanctum::actingAs($other, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/received-store/{$moneyRequestId}")
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
            'id' => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount' => '10.00',
            'asset_code' => 'SZL',
            'note' => null,
            'status' => MoneyRequest::STATUS_FULFILLED,
            'trx' => 'TRX-DONE',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/received-store/{$moneyRequestId}")
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
            'id' => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $frozenRecipient->id,
            'amount' => '10.00',
            'asset_code' => 'SZL',
            'note' => null,
            'status' => MoneyRequest::STATUS_PENDING,
            'trx' => null,
        ]);

        Sanctum::actingAs($frozenRecipient, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/received-store/{$moneyRequestId}")
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
            'id' => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount' => '12.00',
            'asset_code' => 'SZL',
            'note' => null,
            'status' => MoneyRequest::STATUS_PENDING,
            'trx' => 'TRX-IDEM-RECV',
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
    public function test_received_store_rejects_verification_type_none(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id' => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount' => '12.00',
            'asset_code' => 'SZL',
            'note' => null,
            'status' => MoneyRequest::STATUS_PENDING,
            'trx' => 'TRX-IDEM-RECV',
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/received-store/{$moneyRequestId}", [
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

        $this->postJson("/api/request-money/received-store/{$moneyRequestId}")
            ->assertNotFound();
    }

    #[Test]
    public function test_received_store_route_not_registered_when_accept_flag_disabled_even_if_parent_flag_enabled(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
            'maphapay_migration.enable_request_money_accept' => false,
        ]);

        $moneyRequestId = (string) Str::uuid();
        MoneyRequest::query()->create([
            'id' => $moneyRequestId,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'amount' => '12.00',
            'asset_code' => 'SZL',
            'note' => null,
            'status' => MoneyRequest::STATUS_PENDING,
            'trx' => null,
        ]);

        Sanctum::actingAs($this->recipient, ['read', 'write', 'delete']);

        $this->postJson("/api/request-money/received-store/{$moneyRequestId}")
            ->assertNotFound();
    }
}
