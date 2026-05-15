<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Account\Models\MinorAccountLifecycleTransition;
use App\Domain\Account\Observers\MinorAccountLifecycleTransitionObserver;
use App\Domain\Analytics\Contracts\CorMarginBridgeDataPort;
use App\Domain\Analytics\Contracts\UnitEconomicsDataPort;
use App\Domain\Analytics\Infrastructure\LocalDevCorMarginBridgeStubDataPort;
use App\Domain\Analytics\Infrastructure\LocalDevUnitEconomicsStubDataPort;
use App\Domain\Analytics\Infrastructure\NullCorMarginBridgeDataPort;
use App\Domain\Analytics\Infrastructure\NullUnitEconomicsDataPort;
use App\Domain\AuthorizedTransaction\Contracts\MoneyMovementRiskSignalProviderInterface;
use App\Domain\AuthorizedTransaction\Events\AuthorizedTransactionFinalized;
use App\Domain\AuthorizedTransaction\Services\DatabaseMoneyMovementRiskSignalProvider;
use App\Domain\SocialMoney\Observers\AuthorizedTransactionChatSyncObserver;
use App\Domain\SocialMoney\Observers\MoneyRequestChatSyncObserver;
use Illuminate\Support\Facades\Event;
use App\Domain\Governance\Strategies\AssetWeightedVoteStrategy;
use App\Domain\Governance\Strategies\AssetWeightedVotingStrategy;
use App\Domain\Governance\Strategies\OneUserOneVoteStrategy;
use App\Domain\Mobile\Contracts\AppAttestVerifierInterface;
use App\Domain\Mobile\Services\AppAttestVerifier;
use App\Models\MoneyRequest;
use App\Models\Thread;
use App\Observers\ThreadGroupSavingsObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Laravel\Firebase\FirebaseProjectManager;
use L5Swagger\GeneratorFactory;
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
        $this->app->bind('asset_weighted_vote', AssetWeightedVoteStrategy::class);
        $this->app->bind('one_user_one_vote', OneUserOneVoteStrategy::class);
        $this->app->bind(AssetWeightedVotingStrategy::class, AssetWeightedVotingStrategy::class);

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

        $this->app->bind(AppAttestVerifierInterface::class, AppAttestVerifier::class);

        $this->registerRevenueAdvancedDataPorts();
    }

    /**
     * REQ-REV-003 / 004: use bind() so tests and local .env can toggle stub readers without
     * caching the first concrete implementation for the whole process.
     */
    private function registerRevenueAdvancedDataPorts(): void
    {
        $this->app->bind(CorMarginBridgeDataPort::class, function (): CorMarginBridgeDataPort {
            if ($this->app->isProduction()) {
                return new NullCorMarginBridgeDataPort;
            }

            if ((bool) config('maphapay.revenue_cor_bridge_stub_reader', false)) {
                return new LocalDevCorMarginBridgeStubDataPort;
            }

            return new NullCorMarginBridgeDataPort;
        });

        $this->app->bind(UnitEconomicsDataPort::class, function (): UnitEconomicsDataPort {
            if ($this->app->isProduction()) {
                return new NullUnitEconomicsDataPort;
            }

            if ((bool) config('maphapay.revenue_unit_economics_stub_reader', false)) {
                return new LocalDevUnitEconomicsStubDataPort;
            }

            return new NullUnitEconomicsDataPort;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // L5-Swagger: inject the analyser at generation time (not in config) so
        // config:cache / optimize works. Object instances are not serializable.
        $this->app->resolving(GeneratorFactory::class, function () {
            if (config('l5-swagger.defaults.scanOptions.analyser') === null) {
                config(['l5-swagger.defaults.scanOptions.analyser' => new ReflectionAnalyser([
                    new DocBlockAnnotationFactory,
                    new AttributeAnnotationFactory,
                ])]);
            }
        });

        // Configure factory namespace resolution for domain models
        /**
         * @param  class-string<Model>  $modelName
         * @return class-string<Factory>
         */
        Factory::guessFactoryNamesUsing(function (string $modelName): string {
            // For domain models, preserve the full path structure
            if (str_starts_with($modelName, 'App\\Domain\\')) {
                // Replace App\ with Database\Factories\ and append Factory
                $factoryName = str_replace('App\\', 'Database\\Factories\\', $modelName).'Factory';

                /** @var class-string<Factory> */
                return $factoryName;
            }

            // For non-domain models, use the default pattern
            $modelBaseName = class_basename($modelName);

            /** @var class-string<Factory> */
            return 'Database\\Factories\\'.$modelBaseName.'Factory';
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

        // Company account creation: max 3 attempts per hour per user.
        // Company creation is a high-value operation; low limit reduces abuse risk.
        RateLimiter::for('maphapay-company-create', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perHour(3)->by((string) ($user !== null ? $user->id : $request->ip()));
        });

        // Merchant account creation (mobile KYM): same cadence as company create.
        RateLimiter::for('maphapay-merchant-create', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perHour(3)->by((string) ($user !== null ? $user->id : $request->ip()));
        });

        // Minor (child) account creation: higher cap than company (guardians may add several dependents).
        RateLimiter::for('maphapay-minor-create', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perHour(10)->by((string) ($user !== null ? $user->id : $request->ip()));
        });

        // Document upload: max 10 uploads per hour per user (audit requirement).
        RateLimiter::for('maphapay-company-documents', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perHour(10)->by((string) ($user !== null ? $user->id : $request->ip()));
        });

        // Card Domain: Subscriptions, Upgrade, Downgrade, Cancel
        RateLimiter::for('maphapay-card-subscription', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(6)->by((string) ($user !== null ? $user->id : $request->ip()));
        });

        // Card Domain: Create Virtual, Request Physical, Replace
        RateLimiter::for('maphapay-card-creation', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)->by((string) ($user !== null ? $user->id : $request->ip()));
        });

        // Card Domain: Freeze, Unfreeze, Controls, Dispute
        RateLimiter::for('maphapay-card-mutation', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(30)->by((string) ($user !== null ? $user->id : $request->ip()));
        });

        // Card Domain: Reveal PAN/CVV
        RateLimiter::for('maphapay-card-reveal', function (Request $request): Limit {
            $user = $request->user();
            $cardId = $request->route('id') ?? 'unknown-card';
            $userKey = (string) ($user !== null ? $user->id : $request->ip());

            return Limit::perMinute(5)->by("maphapay-card-reveal:{$userKey}:{$cardId}");
        });

        // Card Domain: Processor Webhooks
        RateLimiter::for('maphapay-card-webhook', function (Request $request): Limit {
            $processor = $request->route('processor') ?? 'unknown-processor';

            // 600 per minute per processor
            return Limit::perMinute(600)->by("maphapay-card-webhook:{$processor}");
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

        Thread::observe(ThreadGroupSavingsObserver::class);

        MinorAccountLifecycleTransition::observe(
            MinorAccountLifecycleTransitionObserver::class
        );

        // AuthorizedTransaction status is flipped via a raw UPDATE inside the manager,
        // so we hook into the explicit AuthorizedTransactionFinalized event instead of
        // a model observer. MoneyRequest is updated via Eloquent so observer works there.
        Event::listen(
            AuthorizedTransactionFinalized::class,
            [AuthorizedTransactionChatSyncObserver::class, 'handle'],
        );
        MoneyRequest::observe(MoneyRequestChatSyncObserver::class);

        // Fortify omits route('register') when REGISTRATION_ENABLED=false; marketing views still link to it.
        $this->app->booted(function (): void {
            if (! config('fortify.registration_enabled') && ! Route::has('register')) {
                Route::middleware(config('fortify.middleware', ['web']))
                    ->get('/register', fn () => redirect()->route('app.landing'))
                    ->name('register');
            }
        });
    }
}
