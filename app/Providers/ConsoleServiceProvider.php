<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Basket\Console\Commands\RebalanceBasketsCommand;
use Illuminate\Support\ServiceProvider;

class ConsoleServiceProvider extends ServiceProvider
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        RebalanceBasketsCommand::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->commands($this->commands);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
