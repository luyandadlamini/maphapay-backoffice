<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\AuthorizedTransaction\Contracts\MoneyMovementRiskSignalProviderInterface;
use App\Domain\AuthorizedTransaction\Services\DatabaseMoneyMovementRiskSignalProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Laravel\Firebase\FirebaseProjectManager;
use OpenApi\Analysers\AttributeAnnotationFactory;
use OpenApi\Analysers\DocBlockAnnotationFactory;
use OpenApi\Analysers\ReflectionAnalyser;
use Throwable;
use URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment() !== 'testing') {
            $this->app->register(WaterlineServiceProvider::class);
        }

        // Register voting power strategies
        $this->app->bind('asset_weighted_vote', \App\Domain\Governance\Strategies\AssetWeightedVoteStrategy::class);
        $this->app->bind('one_user_one_vote', \App\Domain\Governance\Strategies\OneUserOneVoteStrategy::class);
        $this->app->bind(\App\Domain\Governance\Strategies\AssetWeightedVotingStrategy::class, \App\Domain\Governance\Strategies\AssetWeightedVotingStrategy::class);

        // Register blockchain service provider
        $this->app->register(BlockchainServiceProvider::class);

        // Override Firebase Messaging to return null when credentials are not configured
        $this->app->singleton(Messaging::class, function ($app) {
            try {
                return $app->make(FirebaseProjectManager::class)->project()->messaging();
            } catch (Throwable) {
                return null;
            }
        });

        $this->app->singleton(
            MoneyMovementRiskSignalProviderInterface::class,
            DatabaseMoneyMovementRiskSignalProvider::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // L5-Swagger: inject the analyser at generation time (not in config) so
        // config:cache / optimize works. Object instances are not serializable.
        $this->app->resolving(\L5Swagger\GeneratorFactory::class, function () {
            if (config('l5-swagger.defaults.scanOptions.analyser') === null) {
                config(['l5-swagger.defaults.scanOptions.analyser' => new ReflectionAnalyser([
                    new DocBlockAnnotationFactory(),
                    new AttributeAnnotationFactory(),
                ])]);
            }
        });

        // Configure factory namespace resolution for domain models
        /**
         * @param class-string<\Illuminate\Database\Eloquent\Model> $modelName
         * @return class-string<Factory>
         */
        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            // For domain models, preserve the full path structure
            if (str_starts_with($modelName, 'App\\Domain\\')) {
                // Replace App\ with Database\Factories\ and append Factory
                $factoryName = str_replace('App\\', 'Database\\Factories\\', $modelName) . 'Factory';

                /** @var class-string<Factory> */
                return $factoryName;
            }

            // For non-domain models, use the default pattern
            $modelBaseName = class_basename($modelName);

            /** @var class-string<Factory> */
            return 'Database\\Factories\\' . $modelBaseName . 'Factory';
        });

        // MaphaPay compatibility: per-user rate limits for money-moving endpoints.
        // Keys include the user ID (or IP for unauthenticated fallback) so limits
        // are isolated per caller and do not bleed across users.
        RateLimiter::for('maphapay-send-money', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)->by((string) ($user !== null ? $user->id : $request->ip()));
        });

        RateLimiter::for('maphapay-request-money', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)->by((string) ($user !== null ? $user->id : $request->ip()));
        });

        RateLimiter::for('maphapay-mtn-initiation', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(5)->by((string) ($user !== null ? $user->id : $request->ip()));
        });

        RateLimiter::for('maphapay-verification', function (Request $request): array {
            $user = $request->user();
            $userKey = (string) ($user !== null ? $user->id : $request->ip());
            $trx = (string) ($request->input('trx') ?? 'missing-trx');

            return [
                Limit::perMinute(10)->by("maphapay-verification:user:{$userKey}"),
                Limit::perMinute(5)->by("maphapay-verification:trx:{$userKey}:{$trx}"),
            ];
        });

        // Treat 'demo' environment as production
        if ($this->app->environment('demo')) {
            // Force production-like settings
            config(['app.debug' => config('demo.debug', false)]);
            config(['app.debug_blacklist' => config('demo.debug_blacklist')]);

            // Force HTTPS in demo environment (but not for local development)
            $localHosts = explode(',', config('app.local_hostnames', 'localhost,127.0.0.1'));
            if (! in_array(request()->getHost(), $localHosts)) {
                URL::forceScheme('https');
            }

            // Apply demo-specific rate limits
            config(['app.rate_limits.api' => config('demo.rate_limits.api', 60)]);
            config(['app.rate_limits.transactions' => config('demo.rate_limits.transactions', 10)]);
        }
    }
}
