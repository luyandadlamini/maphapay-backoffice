<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Force the test process to ignore cached production bootstrap artifacts.
     *
     * The repo currently keeps cached config/routes/events under bootstrap/cache.
     * If tests boot against those files, Laravel resolves as production, ignores
     * phpunit env overrides, and uses the file-backed sqlite database instead of
     * the intended in-memory connection.
     */
    private function isolateBootstrapCachesForTesting(): void
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'maphapay-test-bootstrap-' . getmypid();

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $paths = [
            'APP_CONFIG_CACHE' => $cacheDir . DIRECTORY_SEPARATOR . 'config.php',
            'APP_ROUTES_CACHE' => $cacheDir . DIRECTORY_SEPARATOR . 'routes.php',
            'APP_EVENTS_CACHE' => $cacheDir . DIRECTORY_SEPARATOR . 'events.php',
            'APP_PACKAGES_CACHE' => $cacheDir . DIRECTORY_SEPARATOR . 'packages.php',
            'APP_SERVICES_CACHE' => $cacheDir . DIRECTORY_SEPARATOR . 'services.php',
        ];

        foreach ($paths as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        // Reinforce the phpunit defaults in case the shell environment omits them.
        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';

        if (! getenv('DB_CONNECTION')) {
            putenv('DB_CONNECTION=sqlite');
            $_ENV['DB_CONNECTION'] = 'sqlite';
            $_SERVER['DB_CONNECTION'] = 'sqlite';
        }

        if (! getenv('DB_DATABASE')) {
            putenv('DB_DATABASE=:memory:');
            $_ENV['DB_DATABASE'] = ':memory:';
            $_SERVER['DB_DATABASE'] = ':memory:';
        }
    }

    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $this->isolateBootstrapCachesForTesting();

        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
