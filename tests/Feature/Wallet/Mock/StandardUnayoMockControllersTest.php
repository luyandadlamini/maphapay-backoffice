<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Mock;

use App\Domain\Wallet\Mock\MockWalletStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class StandardUnayoMockControllersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet_mocks.enabled'                                             => true,
            'wallet_mocks.providers.standard_unayo.callback_delay_seconds'     => 0,
            'wallet_mocks.providers.standard_unayo.currency'                   => 'SZL',
            'wallet_mocks.providers.standard_unayo.default_seed_balance_minor' => 5_000_000,
        ]);
        Bus::fake();

        require base_path('routes/mock-wallets.php');

        $keys = Redis::keys('mock:wallet:*');
        if (is_array($keys) && $keys !== []) {
            Redis::del(...array_map(fn (string $key): string => $this->stripRedisPrefix($key), $keys));
        }
    }

    public function test_token_endpoint(): void
    {
        $this->postJson('/__mock/wallets/standard-unayo/oauth/token', ['grant_type' => 'client_credentials'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['access_token']);
    }

    public function test_cashin_accepts_and_persists(): void
    {
        $this->postJson('/__mock/wallets/standard-unayo/unayo/v1/cashin', [
            'reference_id' => 'unayo-r1',
            'amount'       => '100.00',
            'currency'     => 'SZL',
            'external_id'  => 'idem-1',
            'payer'        => ['msisdn' => '26876000001'],
            'description'  => 'Top up',
        ])->assertAccepted()->assertJsonPath('status', 'PENDING');

        $this->assertSame('PENDING', (new MockWalletStore())->getRequest('standard_unayo', 'collect', 'unayo-r1')['status'] ?? null);
    }

    public function test_cashin_dup_and_transient(): void
    {
        $base = ['currency' => 'SZL', 'external_id' => 'x', 'payer' => ['msisdn' => '26876000001']];
        $this->postJson('/__mock/wallets/standard-unayo/unayo/v1/cashin', $base + ['reference_id' => 'u-d', 'amount' => '99.99'])->assertStatus(409);
        $this->postJson('/__mock/wallets/standard-unayo/unayo/v1/cashin', $base + ['reference_id' => 'u-t', 'amount' => '99.98'])->assertStatus(500);
    }

    public function test_cashin_status_shape(): void
    {
        (new MockWalletStore())->putRequest('standard_unayo', 'collect', 'unayo-r2', [
            'account_ref' => '26876000001', 'external_id' => 'idem-1',
            'amount'      => '100.00', 'currency' => 'SZL', 'status' => 'PENDING',
        ]);

        $this->getJson('/__mock/wallets/standard-unayo/unayo/v1/cashin/unayo-r2')
            ->assertOk()
            ->assertJsonPath('payer.msisdn', '26876000001')
            ->assertJsonPath('status', 'PENDING');
    }

    public function test_cashout_accepts_and_persists(): void
    {
        $this->postJson('/__mock/wallets/standard-unayo/unayo/v1/cashout', [
            'reference_id' => 'unayo-d1',
            'amount'       => '50.00',
            'currency'     => 'SZL',
            'external_id'  => 'idem-2',
            'payee'        => ['msisdn' => '26876000001'],
        ])->assertAccepted();

        $this->assertSame('PENDING', (new MockWalletStore())->getRequest('standard_unayo', 'disburse', 'unayo-d1')['status'] ?? null);
    }

    private function stripRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix');

        return is_string($prefix) && $prefix !== '' && str_starts_with($key, $prefix)
            ? substr($key, strlen($prefix))
            : $key;
    }
}
