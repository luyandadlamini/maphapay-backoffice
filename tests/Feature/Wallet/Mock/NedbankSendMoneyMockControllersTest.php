<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Mock;

use App\Domain\Wallet\Mock\MockWalletStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class NedbankSendMoneyMockControllersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet_mocks.enabled'                                                 => true,
            'wallet_mocks.providers.nedbank_send_money.callback_delay_seconds'     => 0,
            'wallet_mocks.providers.nedbank_send_money.currency'                   => 'SZL',
            'wallet_mocks.providers.nedbank_send_money.default_seed_balance_minor' => 5_000_000,
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
        $this->postJson('/__mock/wallets/nedbank-send-money/oauth2/token', ['grant_type' => 'client_credentials'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['access_token']);
    }

    public function test_inbound_accepts_and_persists(): void
    {
        $this->postJson('/__mock/wallets/nedbank-send-money/sendmoney/v1/payments/inbound', [
            'reference_id' => 'ned-r1',
            'amount'       => '100.00',
            'currency'     => 'SZL',
            'external_id'  => 'idem-1',
            'sender'       => ['msisdn' => '26876000001'],
            'memo'         => 'Top up',
        ])->assertAccepted()->assertJsonPath('status', 'PENDING');

        $this->assertSame('PENDING', (new MockWalletStore())->getRequest('nedbank_send_money', 'collect', 'ned-r1')['status'] ?? null);
    }

    public function test_inbound_dup_and_transient(): void
    {
        $base = ['currency' => 'SZL', 'external_id' => 'x', 'sender' => ['msisdn' => '26876000001']];
        $this->postJson('/__mock/wallets/nedbank-send-money/sendmoney/v1/payments/inbound', $base + ['reference_id' => 'n-d', 'amount' => '99.99'])->assertStatus(409);
        $this->postJson('/__mock/wallets/nedbank-send-money/sendmoney/v1/payments/inbound', $base + ['reference_id' => 'n-t', 'amount' => '99.98'])->assertStatus(500);
    }

    public function test_inbound_status_shape(): void
    {
        (new MockWalletStore())->putRequest('nedbank_send_money', 'collect', 'ned-r2', [
            'account_ref' => '26876000001', 'external_id' => 'idem-1',
            'amount'      => '100.00', 'currency' => 'SZL', 'status' => 'PENDING',
        ]);

        $this->getJson('/__mock/wallets/nedbank-send-money/sendmoney/v1/payments/inbound/ned-r2')
            ->assertOk()
            ->assertJsonPath('sender.msisdn', '26876000001')
            ->assertJsonPath('status', 'PENDING');
    }

    public function test_outbound_accepts_and_persists(): void
    {
        $this->postJson('/__mock/wallets/nedbank-send-money/sendmoney/v1/payments/outbound', [
            'reference_id' => 'ned-d1',
            'amount'       => '50.00',
            'currency'     => 'SZL',
            'external_id'  => 'idem-2',
            'recipient'    => ['msisdn' => '26876000001'],
        ])->assertAccepted();

        $this->assertSame('PENDING', (new MockWalletStore())->getRequest('nedbank_send_money', 'disburse', 'ned-d1')['status'] ?? null);
    }

    private function stripRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix');

        return is_string($prefix) && $prefix !== '' && str_starts_with($key, $prefix)
            ? substr($key, strlen($prefix))
            : $key;
    }
}
