<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Providers;

use App\Domain\Wallet\Contracts\UnknownWalletProviderException;
use App\Domain\Wallet\Contracts\WalletProviderAdapter;
use App\Domain\Wallet\Providers\WalletProviderRegistry;
use Tests\TestCase;

final class WalletProviderRegistryTest extends TestCase
{
    public function test_for_returns_mtn_momo_adapter(): void
    {
        $adapter = $this->app->make(WalletProviderRegistry::class)->for('mtn_momo');

        $this->assertInstanceOf(WalletProviderAdapter::class, $adapter);
        $this->assertSame('mtn_momo', $adapter->providerId());
    }

    public function test_for_returns_emali_adapter(): void
    {
        $adapter = $this->app->make(WalletProviderRegistry::class)->for('emali_eswatini_mobile');

        $this->assertInstanceOf(WalletProviderAdapter::class, $adapter);
        $this->assertSame('emali_eswatini_mobile', $adapter->providerId());
    }

    public function test_for_returns_fnb_ewallet_adapter(): void
    {
        $adapter = $this->app->make(WalletProviderRegistry::class)->for('fnb_ewallet');

        $this->assertInstanceOf(WalletProviderAdapter::class, $adapter);
        $this->assertSame('fnb_ewallet', $adapter->providerId());
    }

    public function test_for_unknown_provider_throws_exception_with_provider_id(): void
    {
        try {
            $this->app->make(WalletProviderRegistry::class)->for('unknown');
            $this->fail('Expected UnknownWalletProviderException to be thrown.');
        } catch (UnknownWalletProviderException $exception) {
            $this->assertSame('unknown', $exception->providerId);
        }
    }

    public function test_remaining_phase_one_providers_are_not_wired_yet(): void
    {
        $registry = $this->app->make(WalletProviderRegistry::class);

        foreach ([
            'standard_unayo',
            'nedbank_send_money',
        ] as $providerId) {
            try {
                $registry->for($providerId);
                $this->fail("Expected {$providerId} to remain unwired.");
            } catch (UnknownWalletProviderException $exception) {
                $this->assertSame($providerId, $exception->providerId);
            }
        }
    }
}
