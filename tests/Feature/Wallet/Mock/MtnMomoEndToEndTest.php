<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Mock;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\Wallet\Mock\MockWalletStore;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\ControllerTestCase;

final class MtnMomoEndToEndTest extends ControllerTestCase
{
    private User $mtnUser;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet_mocks.enabled'                                       => true,
            'wallet_mocks.providers.mtn_momo.callback_url'               => 'https://backoffice.test/api/webhooks/wallets/mtn_momo',
            'wallet_mocks.providers.mtn_momo.callback_token'             => 'mock-token',
            'wallet_mocks.providers.mtn_momo.hmac_key'                   => 'mock-secret',
            'wallet_mocks.providers.mtn_momo.callback_delay_seconds'     => 0,
            'wallet_mocks.providers.mtn_momo.default_seed_balance_minor' => 0,
            'wallet_mocks.providers.mtn_momo.currency'                   => 'SZL',
            'maphapay_migration.enable_mtn_momo'                         => true,
            'mtn_momo.base_url'                                          => 'https://mock.local/__mock/wallets/mtn-momo',
            'mtn_momo.subscription_key'                                  => 'sub-key',
            'mtn_momo.api_user'                                          => 'user',
            'mtn_momo.api_key'                                           => 'secret',
            'mtn_momo.target_environment'                                => 'sandbox',
            'mtn_momo.currency'                                          => 'SZL',
            'mtn_momo.callback_token'                                    => 'mock-token',
            'mtn_momo.hmac_key'                                          => 'mock-secret',
            'mtn_momo.verify_callback_token'                             => true,
            'mtn_momo.verify_hmac_signature'                             => true,
        ]);

        require base_path('routes/mock-wallets.php');

        $keys = Redis::keys('mock:wallet:*');
        if (is_array($keys) && $keys !== []) {
            Redis::del(...array_map(fn (string $key): string => $this->stripRedisPrefix($key), $keys));
        }

        $walletStub = $this->createMock(WalletOperationsService::class);
        $walletStub->method('deposit')->willReturn('stub-deposit-id');
        $walletStub->method('withdraw')->willReturn('stub-withdraw-id');
        $this->app->instance(WalletOperationsService::class, $walletStub);

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $this->mtnUser = User::factory()->create(['kyc_status' => 'approved']);
        $account = Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $this->mtnUser->uuid,
            'frozen'    => false,
        ]);
        AccountBalance::factory()
            ->forAccount($account)
            ->forAsset('SZL')
            ->withBalance(0)
            ->create();
    }

    public function test_mtn_request_to_pay_flows_through_mock_callback_and_is_idempotent(): void
    {
        $this->assertSame(0, Artisan::call('mock-wallet:fund', [
            'provider'    => 'mtn_momo',
            'account_ref' => '26876000001',
            'amount'      => '5000.00',
            '--currency'  => 'SZL',
            '--reset'     => true,
        ]));

        Http::fake(fn (HttpClientRequest $request) => $this->handleMockHttp($request));

        Sanctum::actingAs($this->mtnUser, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/mtn/request-to-pay', [
            'idempotency_key' => 'idem-e2e-1',
            'amount'          => '100.00',
            'payer_msisdn'    => '26876000001',
            'note'            => 'Top up',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.transaction.status', MtnMomoTransaction::STATUS_SUCCESSFUL);

        $referenceId = $response->json('data.transaction.mtn_reference_id');
        $this->assertIsString($referenceId);

        $txn = MtnMomoTransaction::query()->where('mtn_reference_id', $referenceId)->firstOrFail();
        $this->assertSame(MtnMomoTransaction::STATUS_SUCCESSFUL, $txn->status);
        $this->assertNotNull($txn->wallet_credited_at);
        $this->assertSame(490_000, (new MockWalletStore())->getBalance('mtn_momo', '26876000001'));

        $replay = $this->postJson('/api/mtn/request-to-pay', [
            'idempotency_key' => 'idem-e2e-1',
            'amount'          => '100.00',
            'payer_msisdn'    => '26876000001',
            'note'            => 'Top up',
        ]);

        $replay->assertOk()
            ->assertJsonPath('data.transaction.id', $txn->id);

        $this->assertSame(1, MtnMomoTransaction::query()->where('idempotency_key', 'idem-e2e-1')->count());
        $this->assertSame(490_000, (new MockWalletStore())->getBalance('mtn_momo', '26876000001'));
    }

    private function handleMockHttp(HttpClientRequest $request): mixed
    {
        if (str_contains($request->url(), '/collection/token/')) {
            return Http::response(['access_token' => 'mock-token', 'token_type' => 'access_token', 'expires_in' => 3600], 200);
        }

        if (str_contains($request->url(), '/collection/v1_0/requesttopay')) {
            $response = $this->callRawJson(
                'POST',
                '/__mock/wallets/mtn-momo/collection/v1_0/requesttopay',
                $request->body(),
                [
                    'Authorization'             => 'Bearer mock-token',
                    'X-Reference-Id'            => $request->header('X-Reference-Id')[0] ?? '',
                    'X-Target-Environment'      => 'sandbox',
                    'Ocp-Apim-Subscription-Key' => 'sub-key',
                ],
            );

            return Http::response((string) $response->getContent(), $response->getStatusCode());
        }

        if ($request->url() === 'https://backoffice.test/api/webhooks/wallets/mtn_momo') {
            $response = $this->callRawJson(
                'POST',
                '/api/webhooks/wallets/mtn_momo',
                $request->body(),
                [
                    'X-Callback-Token' => $request->header('X-Callback-Token')[0] ?? '',
                    'X-Signature'      => $request->header('X-Signature')[0] ?? '',
                ],
            );

            return Http::response((string) $response->getContent(), $response->getStatusCode());
        }

        return Http::response('unexpected URL: ' . $request->url(), 500);
    }

    /**
     * @param  array<string, string>  $headers
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\Response>
     */
    private function callRawJson(string $method, string $uri, string $body, array $headers): \Illuminate\Testing\TestResponse
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $uri, [], [], [], $server, $body);
    }

    private function stripRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix');

        return is_string($prefix) && $prefix !== '' && str_starts_with($key, $prefix)
            ? substr($key, strlen($prefix))
            : $key;
    }
}
