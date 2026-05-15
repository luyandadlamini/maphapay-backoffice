<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Webhooks;

use App\Models\MtnMomoTransaction;
use App\Models\User;
use Tests\TestCase;

final class WalletProviderWebhookControllerTest extends TestCase
{
    public function test_mtn_webhook_updates_successful_collection_transaction(): void
    {
        config([
            'mtn_momo.callback_token'        => 'token',
            'mtn_momo.hmac_key'              => 'secret',
            'mtn_momo.verify_callback_token' => true,
            'mtn_momo.verify_hmac_signature' => true,
        ]);

        $user = User::factory()->create();
        $txn = MtnMomoTransaction::query()->create([
            'id'               => 'txn-1',
            'user_id'          => $user->id,
            'idempotency_key'  => 'idem-1',
            'type'             => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
            'amount'           => '100.00',
            'currency'         => 'SZL',
            'status'           => MtnMomoTransaction::STATUS_PENDING,
            'party_msisdn'     => '26876000001',
            'mtn_reference_id' => 'ref-1',
        ]);

        $body = json_encode([
            'referenceId'            => 'ref-1',
            'status'                 => 'SUCCESSFUL',
            'financialTransactionId' => 'fin-1',
        ], JSON_THROW_ON_ERROR);

        $this->postRawJson('/api/webhooks/wallets/mtn_momo', $body, [
            'X-Callback-Token' => 'token',
            'X-Signature'      => hash_hmac('sha256', $body, 'secret'),
        ])->assertOk();

        $fresh = $txn->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(MtnMomoTransaction::STATUS_SUCCESSFUL, $fresh->status);
        $this->assertSame('fin-1', $fresh->mtn_financial_transaction_id);
    }

    public function test_mtn_webhook_rejects_tampered_signature(): void
    {
        config([
            'mtn_momo.callback_token'        => 'token',
            'mtn_momo.hmac_key'              => 'secret',
            'mtn_momo.verify_callback_token' => true,
            'mtn_momo.verify_hmac_signature' => true,
        ]);

        $this->postRawJson('/api/webhooks/wallets/mtn_momo', '{"referenceId":"ref-1","status":"SUCCESSFUL"}', [
            'X-Callback-Token' => 'token',
            'X-Signature'      => 'bad',
        ])->assertUnauthorized();
    }

    public function test_mtn_webhook_rejects_missing_token(): void
    {
        config([
            'mtn_momo.callback_token'        => 'token',
            'mtn_momo.hmac_key'              => 'secret',
            'mtn_momo.verify_callback_token' => true,
            'mtn_momo.verify_hmac_signature' => false,
        ]);

        $this->postRawJson('/api/webhooks/wallets/mtn_momo', '{"referenceId":"ref-1","status":"SUCCESSFUL"}')
            ->assertUnauthorized();
    }

    /**
     * @param  array<string, string>  $headers
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\Response>
     */
    private function postRawJson(string $uri, string $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', $uri, [], [], [], array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ], $this->serverHeaders($headers)), $body);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
