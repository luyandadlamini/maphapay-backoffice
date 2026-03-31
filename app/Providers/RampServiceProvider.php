<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ramp\Clients\OnramperClient;
use App\Domain\Ramp\Contracts\RampProviderInterface;
use App\Domain\Ramp\Providers\MockRampProvider;
use App\Domain\Ramp\Providers\OnramperProvider;
use App\Domain\Ramp\Services\RampService;
use Illuminate\Support\ServiceProvider;

class RampServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/ramp.php',
            'ramp'
        );

        $this->app->singleton(OnramperClient::class, function () {
            return new OnramperClient();
        });

        $this->app->singleton(RampProviderInterface::class, function ($app) {
            $provider = config('ramp.default_provider', 'mock');

            return match ($provider) {
                'onramper' => new OnramperProvider($app->make(OnramperClient::class)),
                default    => new MockRampProvider(),
            };
        });

        $this->app->singleton(RampService::class, function ($app) {
            return new RampService(
                $app->make(RampProviderInterface::class)
            );
        });
    }
}
