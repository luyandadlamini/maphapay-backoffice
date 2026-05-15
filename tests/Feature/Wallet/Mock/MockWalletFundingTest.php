<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Mock;

use App\Domain\Wallet\Mock\MockWalletStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class MockWalletFundingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet_mocks.enabled'                                       => true,
            'wallet_mocks.providers.mtn_momo.currency'                   => 'SZL',
            'wallet_mocks.providers.mtn_momo.default_seed_balance_minor' => 0,
        ]);

        require base_path('routes/mock-wallets.php');

        $keys = Redis::keys('mock:wallet:*');
        if (is_array($keys) && $keys !== []) {
            Redis::del(...array_map(fn (string $key): string => $this->stripRedisPrefix($key), $keys));
        }
    }

    public function test_fund_endpoint_increments_balance(): void
    {
        $response = $this->postJson('/__mock/wallets/mtn_momo/_admin/fund', [
            'account_ref'  => '26876000001',
            'amount_minor' => 10_000,
            'currency'     => 'SZL',
        ]);

        $response->assertOk()
            ->assertJsonPath('account_ref', '26876000001')
            ->assertJsonPath('balance_minor', 10_000)
            ->assertJsonPath('currency', 'SZL');

        $this->assertSame(10_000, (new MockWalletStore())->getBalance('mtn_momo', '26876000001'));
    }

    public function test_fund_endpoint_can_reset_balance(): void
    {
        $store = new MockWalletStore();
        $store->creditAccount('mtn_momo', '26876000001', 10_000);

        $response = $this->postJson('/__mock/wallets/mtn_momo/_admin/fund', [
            'account_ref'  => '26876000001',
            'amount_minor' => 500,
            'currency'     => 'SZL',
            'reset'        => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('balance_minor', 500);
    }

    public function test_balance_endpoint_returns_balance_and_recent_history(): void
    {
        $store = new MockWalletStore();
        $store->setBalance('mtn_momo', '26876000001', 10_000, 'SZL');
        $store->putRequest('mtn_momo', 'collect', 'ref-1', [
            'account_ref' => '26876000001',
            'status'      => 'PENDING',
        ]);

        $response = $this->getJson('/__mock/wallets/mtn_momo/_admin/balance/26876000001');

        $response->assertOk()
            ->assertJsonPath('account_ref', '26876000001')
            ->assertJsonPath('balance_minor', 10_000)
            ->assertJsonPath('currency', 'SZL')
            ->assertJsonCount(1, 'recent');
    }

    public function test_admin_endpoints_are_forbidden_when_mocks_are_disabled(): void
    {
        config(['wallet_mocks.enabled' => false]);

        $this->postJson('/__mock/wallets/mtn_momo/_admin/fund', [
            'account_ref'  => '26876000001',
            'amount_minor' => 10_000,
            'currency'     => 'SZL',
        ])->assertForbidden();
    }

    public function test_artisan_command_funds_and_resets_balance(): void
    {
        $this->assertSame(0, Artisan::call('mock-wallet:fund', [
            'provider'    => 'mtn_momo',
            'account_ref' => '26876000001',
            'amount'      => '100.00',
            '--currency'  => 'SZL',
        ]));
        $this->assertStringContainsString('mtn_momo 26876000001: balance now 10000 (SZL)', Artisan::output());

        $this->assertSame(0, Artisan::call('mock-wallet:fund', [
            'provider'    => 'mtn_momo',
            'account_ref' => '26876000001',
            'amount'      => '1.50',
            '--currency'  => 'SZL',
            '--reset'     => true,
        ]));

        $this->assertSame(150, (new MockWalletStore())->getBalance('mtn_momo', '26876000001'));
    }

    public function test_artisan_command_refuses_when_mocks_are_disabled(): void
    {
        config(['wallet_mocks.enabled' => false]);

        $this->assertSame(1, Artisan::call('mock-wallet:fund', [
            'provider'    => 'mtn_momo',
            'account_ref' => '26876000001',
            'amount'      => '100.00',
        ]));
    }

    private function stripRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix');

        return is_string($prefix) && $prefix !== '' && str_starts_with($key, $prefix)
            ? substr($key, strlen($prefix))
            : $key;
    }
}
