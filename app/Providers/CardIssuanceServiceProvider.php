<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\CardIssuance\Adapters\DemoCardIssuerAdapter;
use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the CardIssuance domain.
 *
 * Binds the CardIssuerInterface to the configured adapter.
 * Currently supports: "demo". Add a local bank adapter here when ready.
 */
class CardIssuanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CardIssuerInterface::class, function ($app) {
            return new DemoCardIssuerAdapter();
        });
    }

    public function boot(): void
    {
        //
    }
}
