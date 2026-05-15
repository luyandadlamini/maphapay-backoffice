<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Wallet\Providers\Emali\EmaliAdapter;
use App\Domain\Wallet\Providers\Emali\EmaliSettler;
use App\Domain\Wallet\Providers\FnbEwallet\FnbEwalletAdapter;
use App\Domain\Wallet\Providers\FnbEwallet\FnbEwalletSettler;
use App\Domain\Wallet\Providers\MtnMomo\MtnMomoAdapter;
use App\Domain\Wallet\Providers\MtnMomo\MtnMomoSettler;
use App\Domain\Wallet\Providers\StandardUnayo\StandardUnayoAdapter;
use App\Domain\Wallet\Providers\StandardUnayo\StandardUnayoSettler;
use App\Domain\Wallet\Providers\WalletProviderRegistry;
use App\Domain\Wallet\Services\MoneySettlerService;
use Illuminate\Support\ServiceProvider;

final class WalletProviderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WalletProviderRegistry::class);
        $this->app->singleton(MtnMomoAdapter::class);
        $this->app->singleton(MtnMomoSettler::class);
        $this->app->singleton(EmaliAdapter::class);
        $this->app->singleton(EmaliSettler::class);
        $this->app->singleton(FnbEwalletAdapter::class);
        $this->app->singleton(FnbEwalletSettler::class);
        $this->app->singleton(StandardUnayoAdapter::class);
        $this->app->singleton(StandardUnayoSettler::class);

        $this->app->singleton(MoneySettlerService::class, function ($app) {
            return new MoneySettlerService([
                $app->make(MtnMomoSettler::class),
                $app->make(EmaliSettler::class),
                $app->make(FnbEwalletSettler::class),
                $app->make(StandardUnayoSettler::class),
            ]);
        });
    }

    public function boot(): void
    {
    }
}
