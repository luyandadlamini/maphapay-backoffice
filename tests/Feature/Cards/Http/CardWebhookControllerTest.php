<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Http;

use App\Domain\CardSubscriptions\Jobs\ProcessIssuerWebhookJob;
use App\Domain\CardSubscriptions\Models\CardAuditLog;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CardWebhookControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('cardissuance.webhook_secret', 'test_secret');
    }

    public function test_it_rejects_invalid_signature(): void
    {
        $payload = ['event_id' => 'evt_123', 'card_token' => 'tok_123', 'type' => 'authorisation'];

        $response = $this->postJson('/api/webhooks/cards/demo/authorisation', $payload, [
            'X-Webhook-Signature' => 'invalid_signature',
        ]);

        $response->assertStatus(401)
                 ->assertJson(['error' => 'Invalid signature']);
    }

    public function test_it_accepts_valid_signature_and_dispatches_job(): void
    {
        Queue::fake();

        $payload = [
            'event_id'   => 'evt_123',
            'card_token' => 'tok_123',
            'type'       => 'authorisation',
            'amount'     => 1000,
        ];

        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', (string) $rawBody, 'test_secret');

        $response = $this->call(
            'POST',
            '/api/webhooks/cards/demo/authorisation',
            [], // parameters
            [], // cookies
            [], // files
            [
                'HTTP_X-Webhook-Signature' => $signature,
                'CONTENT_TYPE'             => 'application/json',
                'HTTP_ACCEPT'              => 'application/json',
            ],
            $rawBody
        );

        $response->assertStatus(200)
                 ->assertJson(['status' => 'queued']);

        Queue::assertPushed(ProcessIssuerWebhookJob::class, function ($job) use ($payload) {
            return $job->processor === 'demo' &&
                   $job->eventType === 'authorisation' &&
                   $job->payload['event_id'] === $payload['event_id'];
        });

        $this->assertDatabaseHas('card_audit_logs', [
            'action' => 'processor.webhook_received',
        ]);
    }

    public function test_it_handles_idempotency(): void
    {
        Queue::fake();

        CardAuditLog::create([
            'actor_type'  => 'processor',
            'action'      => 'processor.webhook_received',
            'entity_type' => 'processor_event',
            'metadata'    => ['event_id' => 'evt_123_duplicate'],
        ]);

        $payload = [
            'event_id'   => 'evt_123_duplicate',
            'card_token' => 'tok_123',
            'type'       => 'authorisation',
            'amount'     => 1000,
        ];

        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', (string) $rawBody, 'test_secret');

        $response = $this->call(
            'POST',
            '/api/webhooks/cards/demo/authorisation',
            [], // parameters
            [], // cookies
            [], // files
            [
                'HTTP_X-Webhook-Signature' => $signature,
                'CONTENT_TYPE'             => 'application/json',
                'HTTP_ACCEPT'              => 'application/json',
            ],
            $rawBody
        );

        $response->assertStatus(200)
                 ->assertJson(['status' => 'received']);

        Queue::assertNotPushed(ProcessIssuerWebhookJob::class);
    }
}
