<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Wallet\Providers;

use App\Domain\Wallet\Contracts\UnknownWalletProviderException;
use App\Domain\Wallet\Contracts\WalletProviderAdapter;
use App\Domain\Wallet\Providers\WalletProviderRegistry;
use Tests\TestCase;

final class WalletProviderRegistryTest extends TestCase
{
    /**
     * @return iterable<array{0:string}>
     */
    public static function allProviderIds(): iterable
    {
        yield 'mtn_momo' => ['mtn_momo'];
        yield 'emali_eswatini_mobile' => ['emali_eswatini_mobile'];
        yield 'fnb_ewallet' => ['fnb_ewallet'];
        yield 'standard_unayo' => ['standard_unayo'];
        yield 'nedbank_send_money' => ['nedbank_send_money'];
    }

    /**
     * @dataProvider allProviderIds
     */
    public function test_for_returns_adapter(string $providerId): void
    {
        $adapter = $this->app->make(WalletProviderRegistry::class)->for($providerId);

        $this->assertInstanceOf(WalletProviderAdapter::class, $adapter);
        $this->assertSame($providerId, $adapter->providerId());
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
}
