<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Mock;

use App\Domain\Wallet\Mock\MockWalletStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class MtnMomoMockControllersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'wallet_mocks.enabled'                                       => true,
            'wallet_mocks.providers.mtn_momo.callback_delay_seconds'     => 0,
            'wallet_mocks.providers.mtn_momo.currency'                   => 'SZL',
            'wallet_mocks.providers.mtn_momo.default_seed_balance_minor' => 5_000_000,
        ]);
        Bus::fake();

        require base_path('routes/mock-wallets.php');

        $keys = Redis::keys('mock:wallet:*');
        if (is_array($keys) && $keys !== []) {
            Redis::del(...array_map(fn (string $key): string => $this->stripRedisPrefix($key), $keys));
        }
    }

    public function test_collection_token_endpoint_returns_mtn_shape(): void
    {
        $this->postJson('/__mock/wallets/mtn-momo/collection/token/', [], [
            'Authorization' => 'Basic test',
        ])->assertOk()
            ->assertJsonPath('token_type', 'access_token')
            ->assertJsonPath('expires_in', 3600)
            ->assertJsonStructure(['access_token']);
    }

    public function test_request_to_pay_accepts_and_persists_pending_request(): void
    {
        $response = $this->postJson('/__mock/wallets/mtn-momo/collection/v1_0/requesttopay', [
            'amount'       => '100.00',
            'currency'     => 'SZL',
            'externalId'   => 'idem-1',
            'payer'        => ['partyIdType' => 'MSISDN', 'partyId' => '26876000001'],
            'payerMessage' => 'MaphaPay collection',
            'payeeNote'    => 'Top up',
        ], $this->mtnHeaders('ref-1'));

        $response->assertAccepted();

        $request = (new MockWalletStore())->getRequest('mtn_momo', 'collect', 'ref-1');
        $this->assertSame('PENDING', $request['status'] ?? null);
        $this->assertSame('26876000001', $request['account_ref'] ?? null);
    }

    public function test_request_to_pay_returns_duplicate_and_transient_statuses(): void
    {
        $payload = [
            'currency'   => 'SZL',
            'externalId' => 'idem-1',
            'payer'      => ['partyIdType' => 'MSISDN', 'partyId' => '26876000001'],
        ];

        $this->postJson('/__mock/wallets/mtn-momo/collection/v1_0/requesttopay', $payload + [
            'amount' => '99.99',
        ], $this->mtnHeaders('ref-duplicate'))->assertStatus(409);

        $this->postJson('/__mock/wallets/mtn-momo/collection/v1_0/requesttopay', $payload + [
            'amount' => '99.98',
        ], $this->mtnHeaders('ref-transient'))->assertStatus(500);
    }

    public function test_request_to_pay_status_returns_mtn_shape(): void
    {
        (new MockWalletStore())->putRequest('mtn_momo', 'collect', 'ref-1', [
            'account_ref' => '26876000001',
            'external_id' => 'idem-1',
            'amount'      => '100.00',
            'currency'    => 'SZL',
            'status'      => 'PENDING',
        ]);

        $this->getJson('/__mock/wallets/mtn-momo/collection/v1_0/requesttopay/ref-1')
            ->assertOk()
            ->assertJsonPath('externalId', 'idem-1')
            ->assertJsonPath('payer.partyId', '26876000001')
            ->assertJsonPath('status', 'PENDING');
    }

    public function test_disbursement_accepts_and_persists_pending_request(): void
    {
        $response = $this->postJson('/__mock/wallets/mtn-momo/disbursement/v1_0/transfer', [
            'amount'       => '50.00',
            'currency'     => 'SZL',
            'externalId'   => 'idem-2',
            'payee'        => ['partyIdType' => 'MSISDN', 'partyId' => '26876000001'],
            'payerMessage' => 'MaphaPay cash out',
            'payeeNote'    => 'Cash out',
        ], $this->mtnHeaders('ref-2'));

        $response->assertAccepted();

        $request = (new MockWalletStore())->getRequest('mtn_momo', 'disburse', 'ref-2');
        $this->assertSame('PENDING', $request['status'] ?? null);
        $this->assertSame('26876000001', $request['account_ref'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    private function mtnHeaders(string $referenceId): array
    {
        return [
            'Authorization'             => 'Bearer token',
            'X-Reference-Id'            => $referenceId,
            'X-Target-Environment'      => 'sandbox',
            'Ocp-Apim-Subscription-Key' => 'key',
        ];
    }

    private function stripRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix');

        return is_string($prefix) && $prefix !== '' && str_starts_with($key, $prefix)
            ? substr($key, strlen($prefix))
            : $key;
    }
}
