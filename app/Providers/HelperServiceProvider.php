<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class HelperServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        include_once app_path('Domain/Account/Helpers/objects.php');
        include_once app_path('Helpers/faker.php');
    }
}
