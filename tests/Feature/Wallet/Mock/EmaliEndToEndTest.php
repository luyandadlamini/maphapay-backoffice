<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Mock;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\Wallet\Mock\MockWalletStore;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Services\WalletCollectionService;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Exercises the full eMali round-trip:
 * WalletCollectionService → EmaliAdapter → EmaliClient HTTP →
 * (Http::fake routes to our mock controller) → mock controller queues
 * DispatchMockWalletCallbackJob → callback POSTs to webhook controller
 * → MoneySettlerService → EmaliSettler → user wallet credit.
 */
final class EmaliEndToEndTest extends TestCase
{
    private User $emaliUser;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet_mocks.enabled' => true,

            'wallet_mocks.providers.emali_eswatini_mobile.callback_url'           => 'https://backoffice.test/api/webhooks/wallets/emali_eswatini_mobile',
            'wallet_mocks.providers.emali_eswatini_mobile.callback_token'         => 'mock-token',
            'wallet_mocks.providers.emali_eswatini_mobile.hmac_key'               => 'mock-secret',
            'wallet_mocks.providers.emali_eswatini_mobile.callback_delay_seconds' => 0,
            'wallet_mocks.providers.emali_eswatini_mobile.currency'               => 'SZL',

            'emali.base_url'              => 'https://mock.local/__mock/wallets/emali',
            'emali.client_id'             => 'client',
            'emali.client_secret'         => 'secret',
            'emali.callback_token'        => 'mock-token',
            'emali.hmac_key'              => 'mock-secret',
            'emali.verify_callback_token' => true,
            'emali.verify_hmac_signature' => true,
        ]);

        require base_path('routes/mock-wallets.php');

        $keys = Redis::keys('mock:wallet:*');
        if (is_array($keys) && $keys !== []) {
            Redis::del(...array_map(fn (string $key): string => $this->stripRedisPrefix($key), $keys));
        }

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            ['name' => 'Swazi Lilangeni', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $this->emaliUser = User::factory()->create(['kyc_status' => 'approved']);
        $account = Account::factory()->create([
            'uuid'      => (string) Str::uuid(),
            'user_uuid' => $this->emaliUser->uuid,
            'frozen'    => false,
        ]);
        AccountBalance::factory()
            ->forAccount($account)
            ->forAsset('SZL')
            ->withBalance(0)
            ->create();

        // Stub wallet ops to record credits without going through event-sourcing workflow.
        $walletStub = $this->createMock(WalletOperationsService::class);
        $walletStub->method('deposit')->willReturn('stub-deposit-id');
        $walletStub->method('withdraw')->willReturn('stub-withdraw-id');
        $this->app->instance(WalletOperationsService::class, $walletStub);
    }

    public function test_emali_collection_full_round_trip(): void
    {
        Bus::fake();
        Http::fake(fn (HttpClientRequest $request): mixed => $this->routeToMock($request));

        $service = $this->app->make(WalletCollectionService::class);
        $result = $service->collect(
            providerId: 'emali_eswatini_mobile',
            userUuid: $this->emaliUser->uuid,
            providerAccountRef: '26876000001',
            linkToken: 'token',
            amountMinor: 12_500,
            currency: 'SZL',
            idempotencyKey: 'e2e-1',
            callbackUrl: 'https://backoffice.test/api/webhooks/wallets/emali_eswatini_mobile',
            memo: 'E2E top-up',
        );

        $this->assertSame(WalletProviderTransaction::STATUS_PENDING, $result->status);
        $this->assertNotSame('', $result->providerRequestId);

        // Verify the mock controller persisted the pending request.
        $stored = (new MockWalletStore())->getRequest(
            'emali_eswatini_mobile',
            'collect',
            $result->providerRequestId,
        );
        $this->assertNotNull($stored);
        $this->assertSame('PENDING', $stored['status']);
        $this->assertSame('26876000001', $stored['account_ref']);

        // Verify a callback job was queued (would deliver async in production).
        Bus::assertDispatched(\App\Domain\Wallet\Mock\Jobs\DispatchMockWalletCallbackJob::class);

        // Replay the callback synchronously to exercise the webhook → settler → settle path.
        $callbackBody = json_encode([
            'reference_id'             => $result->providerRequestId,
            'status'                   => 'SUCCESSFUL',
            'financial_transaction_id' => 'fin-e2e-1',
        ], JSON_THROW_ON_ERROR);

        $this->callRawJson(
            'POST',
            '/api/webhooks/wallets/emali_eswatini_mobile',
            $callbackBody,
            [
                'X-Callback-Token' => 'mock-token',
                'X-Signature'      => hash_hmac('sha256', $callbackBody, 'mock-secret'),
            ],
        )->assertOk();

        $row = WalletProviderTransaction::query()
            ->where('provider_id', 'emali_eswatini_mobile')
            ->where('provider_request_id', $result->providerRequestId)
            ->firstOrFail();

        $this->assertSame(WalletProviderTransaction::STATUS_SUCCESSFUL, $row->status);
        $this->assertNotNull($row->settled_at);
    }

    private function routeToMock(HttpClientRequest $request): mixed
    {
        if (str_contains($request->url(), '/v1/auth/token')) {
            return Http::response([
                'access_token' => 'mock-emali-token',
                'token_type'   => 'Bearer',
                'expires_in'   => 3600,
            ], 200);
        }

        if (str_contains($request->url(), '/v1/collections') && $request->method() === 'POST') {
            $response = $this->callRawJson(
                'POST',
                '/__mock/wallets/emali/v1/collections',
                $request->body(),
                ['Authorization' => 'Bearer mock-emali-token'],
            );

            return Http::response((string) $response->getContent(), $response->getStatusCode());
        }

        return Http::response('unexpected URL: ' . $request->url(), 500);
    }

    /**
     * @param  array<string, string>  $headers
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\Response>
     */
    private function callRawJson(string $method, string $uri, string $body, array $headers = []): \Illuminate\Testing\TestResponse
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
