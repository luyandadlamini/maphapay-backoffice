<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Mock;

use App\Domain\Wallet\Mock\MockWalletStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class EmaliMockControllersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet_mocks.enabled'                                                    => true,
            'wallet_mocks.providers.emali_eswatini_mobile.callback_delay_seconds'     => 0,
            'wallet_mocks.providers.emali_eswatini_mobile.currency'                   => 'SZL',
            'wallet_mocks.providers.emali_eswatini_mobile.default_seed_balance_minor' => 5_000_000,
        ]);
        Bus::fake();

        require base_path('routes/mock-wallets.php');

        $keys = Redis::keys('mock:wallet:*');
        if (is_array($keys) && $keys !== []) {
            Redis::del(...array_map(fn (string $key): string => $this->stripRedisPrefix($key), $keys));
        }
    }

    public function test_token_endpoint_returns_oauth2_shape(): void
    {
        $this->postJson('/__mock/wallets/emali/v1/auth/token', ['grant_type' => 'client_credentials'])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_in', 3600)
            ->assertJsonStructure(['access_token']);
    }

    public function test_collection_accepts_and_persists_pending_request(): void
    {
        $response = $this->postJson('/__mock/wallets/emali/v1/collections', [
            'reference_id' => 'ref-e1',
            'amount'       => '100.00',
            'currency'     => 'SZL',
            'external_id'  => 'idem-1',
            'payer'        => ['msisdn' => '26876000001'],
            'note'         => 'Top up',
        ]);

        $response->assertAccepted()->assertJsonPath('status', 'PENDING');

        $request = (new MockWalletStore())->getRequest('emali_eswatini_mobile', 'collect', 'ref-e1');
        $this->assertSame('PENDING', $request['status'] ?? null);
        $this->assertSame('26876000001', $request['account_ref'] ?? null);
    }

    public function test_collection_returns_duplicate_and_transient_statuses(): void
    {
        $payload = [
            'currency'    => 'SZL',
            'external_id' => 'idem-1',
            'payer'       => ['msisdn' => '26876000001'],
        ];

        $this->postJson('/__mock/wallets/emali/v1/collections', $payload + [
            'reference_id' => 'ref-dup',
            'amount'       => '99.99',
        ])->assertStatus(409);

        $this->postJson('/__mock/wallets/emali/v1/collections', $payload + [
            'reference_id' => 'ref-tr',
            'amount'       => '99.98',
        ])->assertStatus(500);
    }

    public function test_collection_status_returns_emali_shape(): void
    {
        (new MockWalletStore())->putRequest('emali_eswatini_mobile', 'collect', 'ref-e1', [
            'account_ref' => '26876000001',
            'external_id' => 'idem-1',
            'amount'      => '100.00',
            'currency'    => 'SZL',
            'status'      => 'PENDING',
        ]);

        $this->getJson('/__mock/wallets/emali/v1/collections/ref-e1')
            ->assertOk()
            ->assertJsonPath('external_id', 'idem-1')
            ->assertJsonPath('payer.msisdn', '26876000001')
            ->assertJsonPath('status', 'PENDING');
    }

    public function test_disbursement_accepts_and_persists_pending_request(): void
    {
        $response = $this->postJson('/__mock/wallets/emali/v1/disbursements', [
            'reference_id' => 'ref-d1',
            'amount'       => '50.00',
            'currency'     => 'SZL',
            'external_id'  => 'idem-2',
            'payee'        => ['msisdn' => '26876000001'],
            'note'         => 'Cash out',
        ]);

        $response->assertAccepted();

        $request = (new MockWalletStore())->getRequest('emali_eswatini_mobile', 'disburse', 'ref-d1');
        $this->assertSame('PENDING', $request['status'] ?? null);
        $this->assertSame('26876000001', $request['account_ref'] ?? null);
    }

    private function stripRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix');

        return is_string($prefix) && $prefix !== '' && str_starts_with($key, $prefix)
            ? substr($key, strlen($prefix))
            : $key;
    }
}
