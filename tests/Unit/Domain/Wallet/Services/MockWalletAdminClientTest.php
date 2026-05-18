<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Services;

use App\Domain\Wallet\Exceptions\MockNotAvailableException;
use App\Domain\Wallet\Services\MockWalletAdminClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MockWalletAdminClientTest extends TestCase
{
    public function test_fund_calls_provider_admin_endpoint(): void
    {
        config()->set('services.mock_wallets.base_url', 'https://internal.test/__mock/wallets');
        config()->set('services.mock_wallets.enabled', true);

        Http::fake([
            'internal.test/__mock/wallets/mtn-momo/_admin/fund' => Http::response([
                'balance_minor' => 500000,
                'currency'      => 'SZL',
            ], 200),
        ]);

        $client = app(MockWalletAdminClient::class);
        $result = $client->fund('mtn-momo', '46733123453', 100000, 'test top-up');

        $this->assertSame(500000, $result->balanceMinor);
        $this->assertSame('SZL', $result->currency);

        Http::assertSent(
            fn ($req) => $req->url() === 'https://internal.test/__mock/wallets/mtn-momo/_admin/fund'
            && $req['account_ref'] === '46733123453'
            && $req['amount'] === 100000
            && $req['currency'] === 'SZL'
            && $req['note'] === 'test top-up'
        );
    }

    public function test_balance_calls_provider_admin_endpoint(): void
    {
        config()->set('services.mock_wallets.base_url', 'https://internal.test/__mock/wallets');
        config()->set('services.mock_wallets.enabled', true);

        Http::fake([
            'internal.test/__mock/wallets/fnb-ewallet/_admin/balance/123456' => Http::response([
                'balance_minor' => 250000,
                'currency'      => 'SZL',
                'last_updated'  => '2026-05-15T12:00:00Z',
            ], 200),
        ]);

        $result = app(MockWalletAdminClient::class)->balance('fnb-ewallet', '123456');

        $this->assertSame(250000, $result->balanceMinor);
        $this->assertSame('SZL', $result->currency);
    }

    public function test_throws_when_disabled_in_production(): void
    {
        config()->set('services.mock_wallets.enabled', false);

        $this->expectException(MockNotAvailableException::class);

        app(MockWalletAdminClient::class)->fund('mtn-momo', '46733123453', 100, null);
    }
}
