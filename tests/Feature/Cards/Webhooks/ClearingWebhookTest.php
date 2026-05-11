<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Webhooks;

use App\Domain\CardSubscriptions\Models\CardTransaction;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests settlement matching for clearing webhooks.
 *
 * Per 08-processor-gateway.md §9.
 */
class ClearingWebhookTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return true;
    }

    private function sign(string $body, string $secret = 'demo_webhook_secret'): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /** Clearing for a known transaction → settles it */
    public function test_clearing_webhook_settles_known_transaction(): void
    {
        // Arrange: pre-existing authorised transaction using the correct schema
        $card = \App\Domain\CardIssuance\Models\Card::factory()->create([
            'user_id' => $this->user->id,
            'status'  => 'active',
        ]);
        
        $tx = CardTransaction::create([
            'card_id'                  => $card->id,
            'external_id'              => 'ext_' . uniqid(),
            'processor_transaction_id' => 'txn_clear_001',
            'user_id'                  => $this->user->id,
            'status'                   => 'authorised',
            'amount_cents'             => 5000,
            'currency'                 => 'ZAR',
            'merchant_name'            => 'Test Store',
            'merchant_category'        => '5411',
            'authorization_id'         => 'auth_001',
        ]);

        $payload = [
            'event_id'       => 'evt_clear_001',
            'type'           => 'clearing',
            'transaction_id' => 'txn_clear_001',
            'settled_amount' => 5000,
            'currency'       => 'ZAR',
        ];
        $body = json_encode($payload);
        $sig  = $this->sign($body);

        $response = $this->call(
            'POST',
            '/api/webhooks/cards/demo/clearing',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $body
        );

        $response->assertStatus(200);

        $tx->refresh();
        $this->assertSame('settled', $tx->status);
        $this->assertNotNull($tx->settled_at);
    }

    /** Orphaned settlement (no matching auth) → 200, no error, no job failure */
    public function test_clearing_webhook_for_orphaned_settlement_returns_200(): void
    {
        Queue::fake();

        $payload = [
            'event_id'       => 'evt_orphan_001',
            'type'           => 'clearing',
            'transaction_id' => 'txn_nonexistent_' . uniqid(),
            'settled_amount' => 2500,
            'currency'       => 'ZAR',
        ];
        $body = json_encode($payload);
        $sig  = $this->sign($body);

        $response = $this->call(
            'POST',
            '/api/webhooks/cards/demo/clearing',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $body
        );

        // Must return 200 (do NOT make processor retry forever)
        $response->assertStatus(200);
    }

    /** Invalid signature on clearing → 401 */
    public function test_invalid_signature_on_clearing_returns_401(): void
    {
        $payload = [
            'event_id'       => 'evt_badsig',
            'type'           => 'clearing',
            'transaction_id' => 'txn_badsig',
        ];
        $body   = json_encode($payload);
        $badSig = 'not_valid_signature';

        $response = $this->call(
            'POST',
            '/api/webhooks/cards/demo/clearing',
            [],
            [],
            [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $badSig, 'CONTENT_TYPE' => 'application/json'],
            $body
        );

        $response->assertStatus(401);
    }
}
