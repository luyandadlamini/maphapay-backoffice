<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Wallet\Providers\MtnMomo\MtnMomoAdapter;
use App\Domain\Wallet\Providers\WalletProviderRegistry;
use Illuminate\Support\ServiceProvider;

final class WalletProviderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WalletProviderRegistry::class);
        $this->app->singleton(MtnMomoAdapter::class);
    }

    public function boot(): void
    {
    }
}
