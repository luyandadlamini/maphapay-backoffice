<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Providers;

use App\Domain\Wallet\Contracts\UnknownWalletProviderException;
use App\Domain\Wallet\Contracts\WalletProviderAdapter;
use App\Domain\Wallet\Providers\Emali\EmaliAdapter;
use App\Domain\Wallet\Providers\MtnMomo\MtnMomoAdapter;
use Illuminate\Contracts\Container\Container;

final class WalletProviderRegistry
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function for(string $providerId): WalletProviderAdapter
    {
        return match ($providerId) {
            'mtn_momo'              => $this->container->make(MtnMomoAdapter::class),
            'emali_eswatini_mobile' => $this->container->make(EmaliAdapter::class),
            default                 => throw new UnknownWalletProviderException($providerId),
        };
    }
}
