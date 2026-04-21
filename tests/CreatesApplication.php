<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Force the test process to ignore cached production bootstrap artifacts.
     *
     * The repo currently keeps cached config/routes/events under bootstrap/cache.
     * If tests boot against those files, Laravel resolves as production, ignores
     * phpunit env overrides, and uses the wrong database connection instead of
     * the intended isolated test database.
     */
    private function isolateBootstrapCachesForTesting(): void
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'maphapay-test-bootstrap-' . getmypid();

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $paths = [
            'APP_CONFIG_CACHE'   => $cacheDir . DIRECTORY_SEPARATOR . 'config.php',
            'APP_ROUTES_CACHE'   => $cacheDir . DIRECTORY_SEPARATOR . 'routes.php',
            'APP_EVENTS_CACHE'   => $cacheDir . DIRECTORY_SEPARATOR . 'events.php',
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
            $connection = getenv('TEST_DB_CONNECTION') ?: 'mysql';
            putenv("DB_CONNECTION={$connection}");
            $_ENV['DB_CONNECTION'] = $connection;
            $_SERVER['DB_CONNECTION'] = $connection;
        }

        if (! getenv('DB_DATABASE')) {
            $database = getenv('TEST_DB_DATABASE') ?: 'maphapay_backoffice_test';
            putenv("DB_DATABASE={$database}");
            $_ENV['DB_DATABASE'] = $database;
            $_SERVER['DB_DATABASE'] = $database;
        }

        if (! getenv('DB_HOST')) {
            $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
            putenv("DB_HOST={$host}");
            $_ENV['DB_HOST'] = $host;
            $_SERVER['DB_HOST'] = $host;
        }

        if (! getenv('DB_PORT')) {
            $port = getenv('TEST_DB_PORT') ?: '3306';
            putenv("DB_PORT={$port}");
            $_ENV['DB_PORT'] = $port;
            $_SERVER['DB_PORT'] = $port;
        }

        if (! getenv('DB_USERNAME')) {
            $username = getenv('TEST_DB_USERNAME') ?: 'root';
            putenv("DB_USERNAME={$username}");
            $_ENV['DB_USERNAME'] = $username;
            $_SERVER['DB_USERNAME'] = $username;
        }

        if (! getenv('DB_PASSWORD')) {
            $password = getenv('TEST_DB_PASSWORD') ?: '';
            putenv("DB_PASSWORD={$password}");
            $_ENV['DB_PASSWORD'] = $password;
            $_SERVER['DB_PASSWORD'] = $password;
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
