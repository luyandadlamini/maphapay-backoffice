<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Mock;

use App\Domain\Wallet\Mock\MockWalletStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class FnbEwalletMockControllersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet_mocks.enabled'                                          => true,
            'wallet_mocks.providers.fnb_ewallet.callback_delay_seconds'     => 0,
            'wallet_mocks.providers.fnb_ewallet.currency'                   => 'SZL',
            'wallet_mocks.providers.fnb_ewallet.default_seed_balance_minor' => 5_000_000,
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
        $this->postJson('/__mock/wallets/fnb-ewallet/oauth/v2/token', ['grant_type' => 'client_credentials'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['access_token', 'scope']);
    }

    public function test_credit_accepts_and_persists_pending(): void
    {
        $this->postJson('/__mock/wallets/fnb-ewallet/wallets/v1/credits', [
            'reference_id' => 'fnb-ref-1',
            'amount'       => '100.00',
            'currency'     => 'SZL',
            'external_id'  => 'idem-1',
            'payer'        => ['mobile' => '26876000001'],
            'narration'    => 'Top up',
        ])->assertAccepted()->assertJsonPath('status', 'PENDING');

        $req = (new MockWalletStore())->getRequest('fnb_ewallet', 'collect', 'fnb-ref-1');
        $this->assertSame('PENDING', $req['status'] ?? null);
        $this->assertSame('26876000001', $req['account_ref'] ?? null);
    }

    public function test_credit_returns_duplicate_and_transient(): void
    {
        $base = ['currency' => 'SZL', 'external_id' => 'x', 'payer' => ['mobile' => '26876000001']];

        $this->postJson('/__mock/wallets/fnb-ewallet/wallets/v1/credits', $base + [
            'reference_id' => 'fnb-dup', 'amount' => '99.99',
        ])->assertStatus(409);

        $this->postJson('/__mock/wallets/fnb-ewallet/wallets/v1/credits', $base + [
            'reference_id' => 'fnb-tr', 'amount' => '99.98',
        ])->assertStatus(500);
    }

    public function test_credit_status_shape(): void
    {
        (new MockWalletStore())->putRequest('fnb_ewallet', 'collect', 'fnb-ref-2', [
            'account_ref' => '26876000001',
            'external_id' => 'idem-1',
            'amount'      => '100.00',
            'currency'    => 'SZL',
            'status'      => 'PENDING',
        ]);

        $this->getJson('/__mock/wallets/fnb-ewallet/wallets/v1/credits/fnb-ref-2')
            ->assertOk()
            ->assertJsonPath('payer.mobile', '26876000001')
            ->assertJsonPath('status', 'PENDING');
    }

    public function test_transfer_accepts_and_persists_pending(): void
    {
        $this->postJson('/__mock/wallets/fnb-ewallet/wallets/v1/transfers', [
            'reference_id' => 'fnb-d1',
            'amount'       => '50.00',
            'currency'     => 'SZL',
            'external_id'  => 'idem-2',
            'payee'        => ['mobile' => '26876000001'],
        ])->assertAccepted();

        $req = (new MockWalletStore())->getRequest('fnb_ewallet', 'disburse', 'fnb-d1');
        $this->assertSame('PENDING', $req['status'] ?? null);
    }

    private function stripRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix');

        return is_string($prefix) && $prefix !== '' && str_starts_with($key, $prefix)
            ? substr($key, strlen($prefix))
            : $key;
    }
}
