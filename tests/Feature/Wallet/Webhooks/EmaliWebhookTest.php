<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Webhooks;

use App\Domain\Account\Models\Account;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmaliWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'emali.callback_token'        => 'token',
            'emali.hmac_key'              => 'secret',
            'emali.verify_callback_token' => true,
            'emali.verify_hmac_signature' => true,
        ]);
    }

    public function test_successful_callback_updates_row_and_calls_wallet_deposit(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        $row = WalletProviderTransaction::query()->create([
            'provider_id'         => 'emali_eswatini_mobile',
            'provider_request_id' => 'ref-wh-1',
            'type'                => WalletProviderTransaction::TYPE_COLLECT,
            'status'              => WalletProviderTransaction::STATUS_PENDING,
            'currency'            => 'SZL',
            'amount_minor'        => 25_000,
            'user_uuid'           => $user->uuid,
        ]);

        $walletOps = $this->createMock(WalletOperationsService::class);
        $walletOps->expects($this->once())
            ->method('deposit')
            ->with($account->uuid, 'SZL', '25000', 'emali-collect:ref-wh-1', $this->anything())
            ->willReturn('dep-1');
        $this->app->instance(WalletOperationsService::class, $walletOps);

        $body = json_encode([
            'reference_id' => 'ref-wh-1',
            'status'       => 'SUCCESSFUL',
        ], JSON_THROW_ON_ERROR);

        $this->postRawJson('/api/webhooks/wallets/emali_eswatini_mobile', $body, [
            'X-Callback-Token' => 'token',
            'X-Signature'      => hash_hmac('sha256', $body, 'secret'),
        ])->assertOk();

        $fresh = $row->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(WalletProviderTransaction::STATUS_SUCCESSFUL, $fresh->status);
    }

    public function test_rejects_tampered_signature(): void
    {
        $this->postRawJson('/api/webhooks/wallets/emali_eswatini_mobile', '{"reference_id":"r","status":"SUCCESSFUL"}', [
            'X-Callback-Token' => 'token',
            'X-Signature'      => 'bad',
        ])->assertUnauthorized();
    }

    /**
     * @param  array<string, string>  $headers
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\Response>
     */
    private function postRawJson(string $uri, string $body, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }
}
