<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet\Mock;

use RuntimeException;
use Tests\TestCase;

final class MockRoutesGatingTest extends TestCase
{
    public function test_mock_routes_are_not_loaded_when_disabled(): void
    {
        config(['wallet_mocks.enabled' => false]);

        $this->getJson('/__mock/wallets/mtn_momo/_admin/balance/26876000001')
            ->assertNotFound();
    }

    public function test_production_guard_throws_when_mocks_are_enabled(): void
    {
        config(['wallet_mocks.enabled' => true]);
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Wallet mocks cannot be enabled in production.');

        require base_path('routes/mock-wallets.php');
    }
}
