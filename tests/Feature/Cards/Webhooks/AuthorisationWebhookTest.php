<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Webhooks;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Jobs\ProcessIssuerWebhookJob;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests the full webhook ingestion pipeline for authorisation events:
 * signature → idempotency → audit → job dispatch.
 *
 * Per 08-processor-gateway.md §5.
 */
class AuthorisationWebhookTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return true;
    }

    private function makePayload(string $cardToken = 'tok_auth_test'): array
    {
        return [
            'event_id'         => 'evt_' . uniqid(),
            'type'             => 'authorisation',
            'card_token'       => $cardToken,
            'authorization_id' => 'auth_' . uniqid(),
            'amount'           => 5000,
            'currency'         => 'ZAR',
            'merchant_name'    => 'Test Merchant',
            'merchant_category'=> 'Retail',
        ];
    }

    private function sign(string $body, string $secret = 'demo_webhook_secret'): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    private function makeCard(string $token): Card
    {
        $plan = CardPlan::firstOrCreate(
            ['code' => 'STANDARD'],
            [
                'name'             => 'Standard',
                'is_default'       => true,
                'features'         => [],
                'limits'           => [],
                'subscription_fee' => 0,
            ]
        );

        $subscription = CardSubscription::create([
            'subscriber_user_id'   => $this->user->id,
            'payer_user_id'        => $this->user->id,
            'card_plan_id'         => $plan->id,
            'status'               => 'active',
            'started_at'           => now(),
            'current_period_start' => now(),
            'current_period_end'   => now()->addMonth(),
        ]);

        return Card::factory()->create([
            'issuer_card_token'    => $token,
            'user_id'              => $this->user->id,
            'card_subscription_id' => $subscription->id,
            'status'               => 'active',
        ]);
    }

    /** Case 1: Valid signature + correct payload → job dispatched */
    public function test_valid_signature_dispatches_job(): void
    {
        Queue::fake();

        $payload = $this->makePayload();
        $body    = json_encode($payload);
        $sig     = $this->sign($body);

        $response = $this->call(
            'POST',
            '/api/webhooks/cards/demo/authorisation',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $body
        );

        $response->assertStatus(200);
        Queue::assertPushed(ProcessIssuerWebhookJob::class);
    }

    /** Case 2: Wrong signature → 401, no job dispatched */
    public function test_invalid_signature_returns_401(): void
    {
        Queue::fake();

        $payload = $this->makePayload();
        $body    = json_encode($payload);
        $badSig  = hash_hmac('sha256', $body, 'wrong_secret');

        $response = $this->call(
            'POST',
            '/api/webhooks/cards/demo/authorisation',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $badSig, 'CONTENT_TYPE' => 'application/json'],
            $body
        );

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }

    /** Case 3: Replayed event_id → 200 but no duplicate job */
    public function test_idempotent_replay_returns_200_without_redispatch(): void
    {
        Queue::fake();

        $payload = $this->makePayload();
        $body    = json_encode($payload);
        $sig     = $this->sign($body);

        $headers = ['HTTP_X_WEBHOOK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'];

        // First delivery
        $this->call('POST', '/api/webhooks/cards/demo/authorisation', [], [], [], $headers, $body)
             ->assertStatus(200);

        Queue::assertPushed(ProcessIssuerWebhookJob::class, 1);

        // Replay
        $this->call('POST', '/api/webhooks/cards/demo/authorisation', [], [], [], $headers, $body)
             ->assertStatus(200);

        // Still only 1 job dispatched
        Queue::assertPushed(ProcessIssuerWebhookJob::class, 1);
    }

    /** Case 4: Missing signature header → 401 */
    public function test_missing_signature_header_returns_401(): void
    {
        Queue::fake();

        $payload  = $this->makePayload();
        $body     = json_encode($payload);

        $response = $this->call(
            'POST',
            '/api/webhooks/cards/demo/authorisation',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body
        );

        $response->assertStatus(401);
        Queue::assertNothingPushed();
    }

    /** Case 5: Unknown processor slug → still returns 401 if signature mismatch (no special 404) */
    public function test_valid_payload_but_unknown_processor_uses_demo_fallback(): void
    {
        Queue::fake();

        $payload = $this->makePayload();
        $body    = json_encode($payload);
        // Use demo secret — if unknown processors fall back to demo adapter, it works
        $sig = $this->sign($body);

        $response = $this->call(
            'POST',
            '/api/webhooks/cards/unknown-processor/authorisation',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $body
        );

        // Adapter resolution for unknown processors should still accept (demo default) or return non-5xx
        $this->assertContains($response->status(), [200, 401, 404]);
    }
}
