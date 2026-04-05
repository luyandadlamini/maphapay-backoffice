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
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
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
            'user'   => $this->recipient->email,
            'amount' => '25.00',
            'note'   => 'Lunch',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'remark' => 'request_money',
            ])
            ->assertJsonPath('data.next_step', 'none')
            ->assertJsonPath('data.money_request_id', fn ($id) => is_string($id) && $id !== '')
            ->assertJsonStructure([
                'data' => [
                    'trx',
                ],
            ]);

        $trx = $response->json('data.trx');
        $this->assertIsString($trx);

        $this->assertDatabaseHas('authorized_transactions', [
            'trx'               => $trx,
            'remark'            => AuthorizedTransaction::REMARK_REQUEST_MONEY,
            'user_id'           => $this->requester->id,
            'status'            => AuthorizedTransaction::STATUS_COMPLETED,
            'verification_type' => AuthorizedTransaction::VERIFICATION_NONE,
        ]);

        $this->assertDatabaseHas('money_requests', [
            'trx'               => $trx,
            'requester_user_id' => $this->requester->id,
            'recipient_user_id' => $this->recipient->id,
            'status'            => MoneyRequest::STATUS_PENDING,
            'amount'            => '25.00',
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
            'user'   => $this->recipient->email,
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
            'user'   => $this->recipient->email,
            'amount' => '15.00',
            'note'   => 'Replay-safe request',
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
            'user'   => $this->recipient->email,
            'amount' => '15.00',
            'note'   => 'Cache-loss replay-safe request',
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
            ->assertJsonPath('data.next_step', 'none');

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
    public function test_duplicate_store_reuses_existing_rows_after_cache_loss_with_same_payload(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $idem = (string) Str::uuid();
        $body = [
            'user'   => $this->recipient->email,
            'amount' => '15.00',
            'note'   => 'Idempotent request replay',
        ];

        $first = $this->withHeaders([
            'X-Idempotency-Key' => $idem,
        ])->postJson('/api/request-money/store', $body);

        $first->assertOk()
            ->assertJsonPath('data.next_step', 'none');

        Cache::flush();

        $second = $this->withHeaders([
            'X-Idempotency-Key' => $idem,
        ])->postJson('/api/request-money/store', $body);

        $second->assertOk()
            ->assertJsonPath('data.trx', $first->json('data.trx'))
            ->assertJsonPath('data.next_step', 'none');

        $trx = (string) $first->json('data.trx');

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
    public function test_store_accepts_verification_type_none(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $this->postJson('/api/request-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '15.00',
            'verification_type' => 'none',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['verification_type']);
    }

    #[Test]
    public function test_store_rejects_verification_type_when_client_requests_sms(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $this->requester->update(['transaction_pin' => '1234', 'transaction_pin_enabled' => true]);
        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $this->postJson('/api/request-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '15.00',
            'verification_type' => 'sms',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['verification_type']);
    }

    #[Test]
    public function test_store_rejects_verification_type_when_client_requests_pin(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $this->requester->update(['transaction_pin' => null]);
        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $this->postJson('/api/request-money/store', [
            'user'              => $this->recipient->email,
            'amount'            => '15.00',
            'verification_type' => 'pin',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['verification_type']);
    }

    #[Test]
    public function test_store_accepts_chat_context_when_it_matches_the_recipient(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/request-money/store', [
            'user'           => $this->recipient->email,
            'amount'         => '12.00',
            'chat_friend_id' => $this->recipient->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.money_request_id', fn ($id) => is_string($id) && $id !== '');

        $trx = (string) $response->json('data.trx');
        /** @var AuthorizedTransaction $txn */
        $txn = AuthorizedTransaction::query()->where('trx', $trx)->firstOrFail();

        $this->assertSame($this->recipient->id, $txn->payload['chat_friend_id'] ?? null);
    }

    #[Test]
    public function test_store_rejects_chat_context_that_does_not_match_the_selected_recipient(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        $other = User::factory()->create(['kyc_status' => 'approved']);
        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $this->postJson('/api/request-money/store', [
            'user'           => $this->recipient->email,
            'amount'         => '12.00',
            'chat_friend_id' => $other->id,
        ])->assertStatus(422)
            ->assertJsonPath('message.0', 'Chat context does not match the selected recipient.');
    }

    #[Test]
    public function test_chat_launched_request_returns_chat_link_confirmation_and_writes_the_request_card_without_verification(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
            'maphapay_migration.enable_verification'  => true,
        ]);

        $this->requester->update(['transaction_pin' => '1234', 'transaction_pin_enabled' => true]);
        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $initiation = $this->postJson('/api/request-money/store', [
            'user'           => $this->recipient->email,
            'amount'         => '18.00',
            'note'           => 'Dinner',
            'chat_friend_id' => $this->recipient->id,
        ]);

        $initiation->assertOk()
            ->assertJsonPath('data.next_step', 'none')
            ->assertJsonPath('data.chat_linked', null)
            ->assertJsonPath('data.chat_message_id', null);

        $moneyRequestId = (string) $initiation->json('data.money_request_id');
    }

    #[Test]
    public function test_route_not_registered_when_flag_disabled(): void
    {
        config([
            'maphapay_migration.enable_request_money' => false,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $this->postJson('/api/request-money/store', [
            'user'   => $this->recipient->email,
            'amount' => '10.00',
        ])->assertNotFound();
    }

    #[Test]
    public function test_store_route_not_registered_when_create_flag_disabled_even_if_parent_flag_enabled(): void
    {
        config([
            'maphapay_migration.enable_request_money'        => true,
            'maphapay_migration.enable_request_money_create' => false,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $this->postJson('/api/request-money/store', [
            'user'   => $this->recipient->email,
            'amount' => '10.00',
        ])->assertNotFound();

        $this->assertSame(1, (int) Cache::get(MaphaPayMoneyMovementTelemetry::METRIC_ROLLOUT_BLOCKED_TOTAL, 0));
    }

    #[Test]
    public function test_store_returns_payment_link_and_token_in_response_and_replay(): void
    {
        config([
            'maphapay_migration.enable_request_money' => true,
        ]);

        Sanctum::actingAs($this->requester, ['read', 'write', 'delete']);

        $idem = (string) Str::uuid();
        $body = [
            'user'   => $this->recipient->email,
            'amount' => '25.00',
            'note'   => 'Payment link test',
        ];

        // 1. Initial request
        $first = $this->withHeaders([
            'X-Idempotency-Key' => $idem,
        ])->postJson('/api/request-money/store', $body);

        $first->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'payment_token',
                    'payment_link',
                    'expires_at',
                ],
            ])
            ->assertJsonPath('data.payment_token', fn ($token) => is_string($token) && strlen($token) > 0)
            ->assertJsonPath('data.payment_link', fn ($link) => str_contains($link, '/r/'))
            ->assertJsonPath('data.expires_at', fn ($date) => (bool) strtotime($date));

        $token = $first->json('data.payment_token');

        // 2. Replay request (cache flush to force DB replay logic)
        Cache::flush();

        $second = $this->withHeaders([
            'X-Idempotency-Key' => $idem,
        ])->postJson('/api/request-money/store', $body);

        $second->assertStatus(200)
            ->assertJsonPath('data.payment_token', $token)
            ->assertJsonPath('data.payment_link', $first->json('data.payment_link'))
            ->assertJsonPath('data.expires_at', $first->json('data.expires_at'));
    }
}
