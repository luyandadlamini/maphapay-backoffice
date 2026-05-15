<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Mock;

use App\Domain\Wallet\Mock\Jobs\DispatchMockWalletCallbackJob;
use App\Domain\Wallet\Mock\MockWalletStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class DispatchMockWalletCallbackJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet_mocks.providers.mtn_momo.callback_url'               => 'https://backoffice.test/api/webhooks/wallets/mtn_momo',
            'wallet_mocks.providers.mtn_momo.callback_token'             => 'token',
            'wallet_mocks.providers.mtn_momo.hmac_key'                   => 'secret',
            'wallet_mocks.providers.mtn_momo.default_seed_balance_minor' => 10_000,
            'wallet_mocks.providers.mtn_momo.currency'                   => 'SZL',
        ]);

        $keys = Redis::keys('mock:wallet:*');
        if (is_array($keys) && $keys !== []) {
            Redis::del(...array_map(fn (string $key): string => $this->stripRedisPrefix($key), $keys));
        }
    }

    public function test_successful_collect_debits_external_balance_and_posts_signed_callback(): void
    {
        Http::fake();
        $store = new MockWalletStore();
        $store->setBalance('mtn_momo', '26876000001', 10_000, 'SZL');
        $store->putRequest('mtn_momo', 'collect', 'ref-1', [
            'account_ref'  => '26876000001',
            'amount_minor' => 2_500,
            'amount'       => '25.00',
            'currency'     => 'SZL',
            'status'       => 'PENDING',
        ]);

        (new DispatchMockWalletCallbackJob('mtn_momo', 'collect', 'ref-1'))->handle($store);

        $this->assertSame(7_500, $store->getBalance('mtn_momo', '26876000001'));
        $this->assertSame('SUCCESSFUL', $store->getRequest('mtn_momo', 'collect', 'ref-1')['status'] ?? null);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://backoffice.test/api/webhooks/wallets/mtn_momo'
                && $request->header('X-Callback-Token')[0] === 'token'
                && hash_equals(hash_hmac('sha256', $request->body(), 'secret'), $request->header('X-Signature')[0] ?? '')
                && ($request->data()['status'] ?? null) === 'SUCCESSFUL';
        });
    }

    public function test_silent_timeout_sends_no_callback(): void
    {
        Http::fake();
        $store = new MockWalletStore();
        $store->putRequest('mtn_momo', 'collect', 'ref-timeout', [
            'account_ref'  => '26876000004',
            'amount_minor' => 2_500,
            'amount'       => '25.00',
            'currency'     => 'SZL',
            'status'       => 'PENDING',
        ]);

        (new DispatchMockWalletCallbackJob('mtn_momo', 'collect', 'ref-timeout'))->handle($store);

        Http::assertNothingSent();
        $this->assertSame('PENDING', $store->getRequest('mtn_momo', 'collect', 'ref-timeout')['status'] ?? null);
    }

    public function test_failed_callback_does_not_move_balance(): void
    {
        Http::fake();
        $store = new MockWalletStore();
        $store->setBalance('mtn_momo', '26876000003', 10_000, 'SZL');
        $store->putRequest('mtn_momo', 'collect', 'ref-fail', [
            'account_ref'  => '26876000003',
            'amount_minor' => 2_500,
            'amount'       => '25.00',
            'currency'     => 'SZL',
            'status'       => 'PENDING',
        ]);

        (new DispatchMockWalletCallbackJob('mtn_momo', 'collect', 'ref-fail'))->handle($store);

        $this->assertSame(10_000, $store->getBalance('mtn_momo', '26876000003'));
        $stored = $store->getRequest('mtn_momo', 'collect', 'ref-fail');
        $this->assertSame('FAILED', $stored['status'] ?? null);
        $this->assertSame('INSUFFICIENT_FUNDS', $stored['reason'] ?? null);
    }

    private function stripRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix');

        return is_string($prefix) && $prefix !== '' && str_starts_with($key, $prefix)
            ? substr($key, strlen($prefix))
            : $key;
    }
}
