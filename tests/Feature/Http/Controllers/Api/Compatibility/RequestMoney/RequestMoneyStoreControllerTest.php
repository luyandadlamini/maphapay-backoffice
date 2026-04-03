<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\RequestMoney;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class RequestMoneyStoreControllerTest extends ControllerTestCase
{
    private User $requester;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requester = User::factory()->create(['kyc_status' => 'approved']);
        $this->recipient = User::factory()->create(['kyc_status' => 'approved']);

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
    public function test_store_creates_money_request_and_returns_envelope(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/request-money/store', [
            'user' => $this->recipient->email,
            'amount' => '25.00',
            'note' => 'Lunch',
            'verification_type' => 'sms',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'remark' => 'request_money',
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
            'remark' => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'user_id' => $this->requester->id,
        ]);

        $this->assertDatabaseHas('money_requests', [
            'trx' => $trx,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'status' => MoneyRequest::STATUS_AWAITING_OTP,
            'amount' => '25.00',
        ]);
    }

    #[Test]
    public function test_store_embeds_idempotency_key_in_payload(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $response = $this->withHeaders([
            'X-Idempotency-Key' => '00000000-0000-0000-0000-000000000010',
        ])->postJson('/api/request-money/store', [
            'user' => $this->recipient->email,
            'amount' => '15.00',
        ]);

        $response->assertOk();

        $trx = $response->json('data.trx');
        /** @var AuthorizedTransaction $txn */
        $txn = AuthorizedTransaction::where('trx', $trx)->firstOrFail();

        $this->assertSame('00000000-0000-0000-0000-000000000010', $txn->payload['_idempotency_key'] ?? null);
    }

    #[Test]
    public function test_duplicate_store_with_same_idempotency_key_replays_without_creating_extra_rows(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $idem = (string) Str::uuid();
        $body = [
            'user' => $this->recipient->email,
            'amount' => '15.00',
            'note' => 'Replay-safe request',
            'verification_type' => 'sms',
        ];

        $first = $this->withHeaders([
            'X-Idempotency-Key' => $idem,
        ])->postJson('/api/request-money/store', $body);
        $first->assertOk();
        $trx = (string) $first->json('data.trx');

        $second = $this->withHeaders([
            'X-Idempotency-Key' => $idem,
        ])->postJson('/api/request-money/store', $body);
        $second->assertOk()
            ->assertJsonPath('data.trx', $trx);

        $this->assertSame(1, AuthorizedTransaction::query()
            ->where('remark', AuthorizedTransaction::REMARK_REQUEST_MONEY)
            ->where('user_id', $this->requester->id)
            ->where('trx', $trx)
            ->count());

        $this->assertSame(1, MoneyRequest::query()
            ->where('requester_user_id', $this->requester->id)
            ->where('recipient_user_id', $this->recipient->id)
            ->where('amount', '15.00')
            ->where('trx', $trx)
            ->count());

        $this->assertSame(1, (int) Cache::get(MaphaPayMoneyMovementTelemetry::METRIC_RETRIES_TOTAL, 0));
    }

    #[Test]
    public function test_duplicate_store_with_same_idempotency_key_reuses_existing_rows_after_idempotency_cache_loss(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $idem = (string) Str::uuid();
        $body = [
            'user' => $this->recipient->email,
            'amount' => '15.00',
            'note' => 'Cache-loss replay-safe request',
            'verification_type' => 'sms',
        ];

        $first = $this->withHeaders([
            'X-Idempotency-Key' => $idem,
        ])->postJson('/api/request-money/store', $body);
        $first->assertOk();
        $trx = (string) $first->json('data.trx');

        Cache::flush();

        $second = $this->withHeaders([
            'X-Idempotency-Key' => $idem,
        ])->postJson('/api/request-money/store', $body);
        $second->assertOk()
            ->assertJsonPath('data.trx', $trx)
            ->assertJsonPath('data.next_step', 'otp');

        $this->assertSame(1, AuthorizedTransaction::query()
            ->where('remark', AuthorizedTransaction::REMARK_REQUEST_MONEY)
            ->where('user_id', $this->requester->id)
            ->where('trx', $trx)
            ->count());

        $this->assertSame(1, MoneyRequest::query()
            ->where('requester_user_id', $this->requester->id)
            ->where('recipient_user_id', $this->recipient->id)
            ->where('amount', '15.00')
            ->where('trx', $trx)
            ->count());
    }

    #[Test]
    public function test_store_rejects_verification_type_none(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $this->postJson('/api/request-money/store', [
            'user' => $this->recipient->email,
            'amount' => '15.00',
            'verification_type' => 'none',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['verification_type']);
    }

    #[Test]
    public function test_store_uses_pin_policy_when_requester_has_a_transaction_pin_even_if_client_requests_sms(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $this->requester->update(['transaction_pin' => '1234']);
        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/request-money/store', [
            'user' => $this->recipient->email,
            'amount' => '15.00',
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
        $this->assertSame('user_preference', $txn->payload['_verification_policy']['reason'] ?? null);
        $this->assertSame('sms', $txn->payload['_verification_policy']['client_hint'] ?? null);
    }

    #[Test]
    public function test_store_uses_otp_policy_when_requester_has_no_pin_even_if_client_requests_pin(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $this->requester->update(['transaction_pin' => null]);
        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/request-money/store', [
            'user' => $this->recipient->email,
            'amount' => '15.00',
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
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config([
            'maphapay_migration.enable_request_money' => false,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $this->postJson('/api/request-money/store', [
            'user' => $this->recipient->email,
            'amount' => '10.00',
        ])->assertNotFound();
    }

    #[Test]
    public function test_store_route_not_registered_when_create_flag_disabled_even_if_parent_flag_enabled(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
            'maphapay_migration.enable_request_money_create' => false,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $this->postJson('/api/request-money/store', [
            'user' => $this->recipient->email,
            'amount' => '10.00',
        ])->assertNotFound();

        $this->assertSame(1, (int) Cache::get(MaphaPayMoneyMovementTelemetry::METRIC_ROLLOUT_BLOCKED_TOTAL, 0));
    }
}
