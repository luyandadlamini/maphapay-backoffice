<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Providers;

use App\Domain\CardIssuance\Adapters\DemoCardIssuerAdapter;
use App\Domain\CardIssuance\Adapters\RainCardIssuerAdapter;
use App\Domain\CardIssuance\Adapters\StripeCardIssuerAdapter;
use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use App\Domain\CardIssuance\Events\AuthorizationApproved;
use App\Domain\CardIssuance\Events\AuthorizationDeclined;
use App\Domain\CardIssuance\Events\CardProvisioned;
use App\Domain\CardIssuance\ValueObjects\StripeUsdToSzlConverter;
use App\Domain\CardSubscriptions\Listeners\ApplyRiskFreezeOnCriticalEvent;
use App\Domain\CardSubscriptions\Listeners\BroadcastSubscriptionStateToMobile;
use App\Domain\CardSubscriptions\Listeners\EmitCardLifecycleAuditLog;
use App\Domain\CardSubscriptions\Listeners\EmitMrrAggregateRecalc;
use App\Domain\CardSubscriptions\Listeners\NotifyCardFeeCharged;
use App\Domain\CardSubscriptions\Listeners\NotifyCardSubscriptionLifecycle;
use App\Domain\CardSubscriptions\Listeners\NotifyCardJitAuthorization;
use App\Domain\CardSubscriptions\Listeners\NotifyMinorCardRequest;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Domain\CardSubscriptions\Services\CardBillingService;
use App\Domain\CardSubscriptions\Services\CardDisputeService;
use App\Domain\CardSubscriptions\Services\CardEntitlementService;
use App\Domain\CardSubscriptions\Services\CardFeeService;
use App\Domain\CardSubscriptions\Services\CardLifecycleService;
use App\Domain\CardSubscriptions\Services\CardRevealService;
use App\Domain\CardSubscriptions\Services\CardRiskService;
use App\Domain\CardSubscriptions\Services\CardSubscriptionService;
use App\Domain\CardSubscriptions\Services\MinorCardSubscriptionService;
use App\Domain\CardSubscriptions\Services\PhysicalCardOrderService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Spatie\EventSourcing\Facades\Projectionist;
use Stripe\StripeClient;

class CardSubscriptionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CardEntitlementService::class);
        $this->app->singleton(CardSubscriptionService::class);
        $this->app->singleton(CardBillingService::class);
        $this->app->singleton(CardFeeService::class);
        $this->app->singleton(CardLifecycleService::class);
        $this->app->singleton(CardRiskService::class);
        $this->app->singleton(CardAuditService::class);
        $this->app->singleton(CardRevealService::class);
        $this->app->singleton(CardDisputeService::class);
        $this->app->singleton(PhysicalCardOrderService::class);
        $this->app->singleton(MinorCardSubscriptionService::class);
        $this->app->singleton(StripeUsdToSzlConverter::class, fn (): StripeUsdToSzlConverter => new StripeUsdToSzlConverter(
            rate: (float) config('cards.processors.stripe.fx_rate_usd_szl', 18.50),
        ));
        $this->app->singleton(StripeClient::class, fn (): StripeClient => new StripeClient([
            'api_key'        => (string) (config('cards.processors.stripe.secret_key') ?: 'sk_test_missing'),
            'stripe_version' => (string) config('cards.processors.stripe.api_version'),
        ]));

        $this->app->bind(CardIssuerInterface::class, function ($app): CardIssuerInterface {
            $driver = (string) config('cards.default_processor', config('cardissuance.default_issuer', 'demo'));

            return match ($driver) {
                'demo' => $app->make(DemoCardIssuerAdapter::class),
                'rain' => new RainCardIssuerAdapter((array) config('cards.processors.rain', config('cardissuance.issuers.rain', []))),
                'stripe' => new StripeCardIssuerAdapter(
                    stripe: $app->make(StripeClient::class),
                    converter: $app->make(StripeUsdToSzlConverter::class),
                    webhookSecret: (string) config('cards.processors.stripe.webhook_secret'),
                ),
                default => throw new LogicException("Unknown card processor: {$driver}"),
            };
        });
    }

    public function boot(): void
    {
        if (! class_exists(Projectionist::class)) {
            return;
        }

        Projectionist::addReactors([
            NotifyCardSubscriptionLifecycle::class,
            NotifyCardFeeCharged::class,
            ApplyRiskFreezeOnCriticalEvent::class,
            BroadcastSubscriptionStateToMobile::class,
            NotifyMinorCardRequest::class,
            EmitMrrAggregateRecalc::class,
        ]);

        Event::listen(CardProvisioned::class, EmitCardLifecycleAuditLog::class);
        Event::listen(AuthorizationApproved::class, EmitCardLifecycleAuditLog::class);
        Event::listen(AuthorizationDeclined::class, EmitCardLifecycleAuditLog::class);

        Event::listen(AuthorizationApproved::class, NotifyCardJitAuthorization::class);
        Event::listen(AuthorizationDeclined::class, NotifyCardJitAuthorization::class);
    }
}
