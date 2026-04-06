<?php

declare(strict_types=1);

namespace App\Providers;

use App\Testing\TestEventSerializer;
use Illuminate\Support\ServiceProvider;
use Spatie\EventSourcing\EventSerializers\EventSerializer;

class TestingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        if ($this->app->environment('testing')) {
            // Use simpler event serializer for tests to avoid timeout issues
            $this->app->bind(EventSerializer::class, TestEventSerializer::class);
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
