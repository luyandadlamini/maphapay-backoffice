<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\MobilePayment;

use App\Domain\Commerce\Enums\MerchantStatus;
use App\Domain\Commerce\Models\Merchant;
use App\Domain\MobilePayment\Enums\PaymentIntentStatus;
use App\Domain\MobilePayment\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentIntentControllerTest extends TestCase
{
    protected User $user;

    protected Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->user = User::factory()->create();

        $this->merchant = Merchant::create([
            'public_id'         => 'merchant_test_' . Str::random(8),
            'display_name'      => 'Test Merchant',
            'icon_url'          => 'https://example.com/icon.png',
            'accepted_assets'   => ['USDC'],
            'accepted_networks' => ['SOLANA', 'TRON'],
            'status'            => MerchantStatus::ACTIVE,
        ]);
    }

    protected function actingAsMobileUser(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);
    }

    public function test_create_intent_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/payments/intents', [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => '12.00',
            'asset'            => 'USDC',
            'preferredNetwork' => 'SOLANA',
        ]);

        $response->assertUnauthorized();
    }

    public function test_create_intent_validates_required_fields(): void
    {
        $this->actingAsMobileUser();

        $response = $this->postJson('/api/v1/payments/intents', []);

        $response->assertUnprocessable();
    }

    public function test_create_intent_rejects_unsupported_asset(): void
    {
        $this->actingAsMobileUser();

        $response = $this->postJson('/api/v1/payments/intents', [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => '12.00',
            'asset'            => 'ETH',
            'preferredNetwork' => 'SOLANA',
        ]);

        $response->assertUnprocessable();
    }

    public function test_create_intent_rejects_unsupported_network(): void
    {
        $this->actingAsMobileUser();

        $response = $this->postJson('/api/v1/payments/intents', [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => '12.00',
            'asset'            => 'USDC',
            'preferredNetwork' => 'ETHEREUM',
        ]);

        $response->assertUnprocessable();
    }

    public function test_create_intent_rejects_zero_amount(): void
    {
        $this->actingAsMobileUser();

        $response = $this->postJson('/api/v1/payments/intents', [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => '0',
            'asset'            => 'USDC',
            'preferredNetwork' => 'SOLANA',
        ]);

        $response->assertUnprocessable();
    }

    public function test_create_intent_rejects_numeric_json_amount(): void
    {
        $this->actingAsMobileUser();

        $response = $this->postJson('/api/v1/payments/intents', [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => 12.0,
            'asset'            => 'USDC',
            'preferredNetwork' => 'SOLANA',
        ]);

        $response->assertUnprocessable();
    }

    public function test_create_intent_rejects_integer_json_amount(): void
    {
        $this->actingAsMobileUser();

        $response = $this->postJson('/api/v1/payments/intents', [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => 12,
            'asset'            => 'USDC',
            'preferredNetwork' => 'SOLANA',
        ]);

        $response->assertUnprocessable();
    }

    public function test_create_intent_rejects_malformed_amount_string(): void
    {
        $this->actingAsMobileUser();

        $response = $this->postJson('/api/v1/payments/intents', [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => '12.00.00',
            'asset'            => 'USDC',
            'preferredNetwork' => 'SOLANA',
        ]);

        $response->assertUnprocessable();
    }

    public function test_create_intent_returns_201_with_valid_data(): void
    {
        $this->actingAsMobileUser();

        $response = $this->postJson('/api/v1/payments/intents', [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => '12.00',
            'asset'            => 'USDC',
            'preferredNetwork' => 'SOLANA',
            'shield'           => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'intentId',
                    'merchantId',
                    'merchant' => ['displayName', 'iconUrl'],
                    'asset',
                    'network',
                    'amount',
                    'status',
                    'shieldEnabled',
                    'feesEstimate' => ['nativeAsset', 'amount', 'usdApprox'],
                    'createdAt',
                    'expiresAt',
                ],
            ])
            ->assertJsonPath('data.asset', 'USDC')
            ->assertJsonPath('data.network', 'SOLANA')
            ->assertJsonPath('data.status', 'AWAITING_AUTH')
            ->assertJsonPath('data.shieldEnabled', true);
    }

    public function test_create_intent_returns_404_for_unknown_merchant(): void
    {
        $this->actingAsMobileUser();

        $response = $this->postJson('/api/v1/payments/intents', [
            'merchantId'       => 'nonexistent_merchant',
            'amount'           => '12.00',
            'asset'            => 'USDC',
            'preferredNetwork' => 'SOLANA',
        ]);

        $response->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_create_intent_idempotency_key_header_matches_middleware_precedence(): void
    {
        $this->actingAsMobileUser();

        $payload = [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => '5.00',
            'asset'            => 'USDC',
            'preferredNetwork' => 'SOLANA',
        ];

        $key = str_repeat('b', 16);

        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/payments/intents', $payload);

        $first->assertCreated();

        $second = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/payments/intents', $payload);

        $second->assertCreated()
            ->assertHeader('X-Idempotency-Replayed', 'true');

        $this->assertSame(
            $first->json('data.intentId'),
            $second->json('data.intentId'),
        );
    }

    public function test_create_intent_falls_back_to_x_idempotency_key_header(): void
    {
        $this->actingAsMobileUser();

        $payload = [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => '7.00',
            'asset'            => 'USDC',
            'preferredNetwork' => 'SOLANA',
        ];

        $key = str_repeat('c', 16);

        $first = $this->withHeaders(['X-Idempotency-Key' => $key])
            ->postJson('/api/v1/payments/intents', $payload);

        $first->assertCreated();

        $stored = PaymentIntent::query()
            ->where('user_id', $this->user->id)
            ->where('idempotency_key', $key)
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame($first->json('data.intentId'), $stored->public_id);
    }

    public function test_create_intent_x_idempotency_key_replays_via_middleware(): void
    {
        $this->actingAsMobileUser();

        $payload = [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => '6.00',
            'asset'            => 'USDC',
            'preferredNetwork' => 'SOLANA',
        ];

        $key = str_repeat('h', 16);

        $first = $this->withHeaders(['X-Idempotency-Key' => $key])
            ->postJson('/api/v1/payments/intents', $payload);

        $first->assertCreated();

        $second = $this->withHeaders(['X-Idempotency-Key' => $key])
            ->postJson('/api/v1/payments/intents', $payload);

        $second->assertCreated()
            ->assertHeader('X-Idempotency-Replayed', 'true');

        $this->assertSame(
            $first->json('data.intentId'),
            $second->json('data.intentId'),
        );
    }

    public function test_create_intent_idempotency_header_overrides_body_key(): void
    {
        $this->actingAsMobileUser();

        $bodyKey = str_repeat('d', 16);
        $headerKey = str_repeat('e', 16);

        $payload = [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => '3.00',
            'asset'            => 'USDC',
            'preferredNetwork' => 'SOLANA',
            'idempotencyKey'   => $bodyKey,
        ];

        $response = $this->withHeaders(['Idempotency-Key' => $headerKey])
            ->postJson('/api/v1/payments/intents', $payload);

        $response->assertCreated();

        $stored = PaymentIntent::query()
            ->where('user_id', $this->user->id)
            ->where('idempotency_key', $headerKey)
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame($response->json('data.intentId'), $stored->public_id);

        $this->assertNull(
            PaymentIntent::query()
                ->where('user_id', $this->user->id)
                ->where('idempotency_key', $bodyKey)
                ->first(),
        );
    }

    public function test_create_intent_idempotency_key_header_precedes_x_idempotency_key_for_domain(): void
    {
        $this->actingAsMobileUser();

        $primary = str_repeat('f', 16);
        $secondary = str_repeat('g', 16);

        $payload = [
            'merchantId'       => $this->merchant->public_id,
            'amount'           => '4.00',
            'asset'            => 'USDC',
            'preferredNetwork' => 'SOLANA',
        ];

        $response = $this->withHeaders([
            'Idempotency-Key'   => $primary,
            'X-Idempotency-Key' => $secondary,
        ])->postJson('/api/v1/payments/intents', $payload);

        $response->assertCreated();

        $stored = PaymentIntent::query()
            ->where('user_id', $this->user->id)
            ->where('idempotency_key', $primary)
            ->first();

        $this->assertNotNull($stored);
        $this->assertNull(
            PaymentIntent::query()
                ->where('user_id', $this->user->id)
                ->where('idempotency_key', $secondary)
                ->first(),
        );
    }

    public function test_show_intent_returns_200(): void
    {
        $this->actingAsMobileUser();

        $intent = PaymentIntent::create([
            'public_id'              => 'pi_' . Str::random(20),
            'user_id'                => $this->user->id,
            'merchant_id'            => $this->merchant->id,
            'asset'                  => 'USDC',
            'network'                => 'SOLANA',
            'amount'                 => '25.00',
            'status'                 => PaymentIntentStatus::AWAITING_AUTH,
            'shield_enabled'         => false,
            'fees_estimate'          => ['nativeAsset' => 'SOL', 'amount' => '0.00004', 'usdApprox' => '0.01'],
            'required_confirmations' => 32,
            'expires_at'             => now()->addMinutes(15),
        ]);

        $response = $this->getJson("/api/v1/payments/intents/{$intent->public_id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.intentId', $intent->public_id)
            ->assertJsonPath('data.status', 'AWAITING_AUTH');
    }

    public function test_show_intent_returns_404_for_other_users_intent(): void
    {
        $this->actingAsMobileUser();

        $otherUser = User::factory()->create();

        $intent = PaymentIntent::create([
            'public_id'              => 'pi_' . Str::random(20),
            'user_id'                => $otherUser->id,
            'merchant_id'            => $this->merchant->id,
            'asset'                  => 'USDC',
            'network'                => 'SOLANA',
            'amount'                 => '25.00',
            'status'                 => PaymentIntentStatus::AWAITING_AUTH,
            'shield_enabled'         => false,
            'required_confirmations' => 32,
            'expires_at'             => now()->addMinutes(15),
        ]);

        $response = $this->getJson("/api/v1/payments/intents/{$intent->public_id}");

        $response->assertNotFound();
    }

    public function test_submit_intent_returns_200(): void
    {
        $this->actingAsMobileUser();

        $intent = PaymentIntent::create([
            'public_id'              => 'pi_' . Str::random(20),
            'user_id'                => $this->user->id,
            'merchant_id'            => $this->merchant->id,
            'asset'                  => 'USDC',
            'network'                => 'SOLANA',
            'amount'                 => '12.00',
            'status'                 => PaymentIntentStatus::AWAITING_AUTH,
            'shield_enabled'         => false,
            'fees_estimate'          => ['nativeAsset' => 'SOL', 'amount' => '0.00004', 'usdApprox' => '0.01'],
            'required_confirmations' => 32,
            'expires_at'             => now()->addMinutes(15),
        ]);

        $response = $this->postJson("/api/v1/payments/intents/{$intent->public_id}/submit", [
            'auth' => 'biometric',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $intent->refresh();
        $this->assertEquals(PaymentIntentStatus::PENDING, $intent->status);
        $this->assertNotNull($intent->tx_hash);
    }

    public function test_submit_already_submitted_intent_returns_409(): void
    {
        $this->actingAsMobileUser();

        $intent = PaymentIntent::create([
            'public_id'              => 'pi_' . Str::random(20),
            'user_id'                => $this->user->id,
            'merchant_id'            => $this->merchant->id,
            'asset'                  => 'USDC',
            'network'                => 'SOLANA',
            'amount'                 => '12.00',
            'status'                 => PaymentIntentStatus::PENDING,
            'shield_enabled'         => false,
            'required_confirmations' => 32,
            'expires_at'             => now()->addMinutes(15),
        ]);

        $response = $this->postJson("/api/v1/payments/intents/{$intent->public_id}/submit", [
            'auth' => 'biometric',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'INTENT_ALREADY_SUBMITTED');
    }

    public function test_cancel_intent_returns_200(): void
    {
        $this->actingAsMobileUser();

        $intent = PaymentIntent::create([
            'public_id'              => 'pi_' . Str::random(20),
            'user_id'                => $this->user->id,
            'merchant_id'            => $this->merchant->id,
            'asset'                  => 'USDC',
            'network'                => 'SOLANA',
            'amount'                 => '14.99',
            'status'                 => PaymentIntentStatus::AWAITING_AUTH,
            'shield_enabled'         => false,
            'required_confirmations' => 32,
            'expires_at'             => now()->addMinutes(15),
        ]);

        $response = $this->postJson("/api/v1/payments/intents/{$intent->public_id}/cancel", [
            'reason' => 'user_cancelled',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $intent->refresh();
        $this->assertEquals(PaymentIntentStatus::CANCELLED, $intent->status);
        $this->assertEquals('user_cancelled', $intent->cancel_reason);
    }

    public function test_cancel_submitted_intent_returns_409(): void
    {
        $this->actingAsMobileUser();

        $intent = PaymentIntent::create([
            'public_id'              => 'pi_' . Str::random(20),
            'user_id'                => $this->user->id,
            'merchant_id'            => $this->merchant->id,
            'asset'                  => 'USDC',
            'network'                => 'SOLANA',
            'amount'                 => '14.99',
            'status'                 => PaymentIntentStatus::PENDING,
            'shield_enabled'         => false,
            'required_confirmations' => 32,
            'expires_at'             => now()->addMinutes(15),
        ]);

        $response = $this->postJson("/api/v1/payments/intents/{$intent->public_id}/cancel", [
            'reason' => 'user_cancelled',
        ]);

        $response->assertStatus(409);
    }
}
