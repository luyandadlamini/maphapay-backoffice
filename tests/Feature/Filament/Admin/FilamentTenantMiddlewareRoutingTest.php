<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Filament\Admin\Resources\MultiSigWalletResource\Pages\ListMultiSigWallets;
use App\Filament\Admin\Resources\AdjustmentRequestResource\Pages\ListAdjustmentRequests;
use App\Http\Middleware\FilamentTenantMiddleware;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Stancl\Tenancy\Tenancy;

beforeEach(function (): void {
    $this->tenantId = (string) Str::uuid();
    $databaseName = DB::connection('central')->getDatabaseName();

    DB::connection('central')->table('tenants')->insert([
        'id' => $this->tenantId,
        'name' => 'Admin route tenant',
        'plan' => 'default',
        'team_id' => null,
        'trial_ends_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        'data' => json_encode(['tenancy_db_name' => $databaseName]),
    ]);
});

afterEach(function (): void {
    app(Tenancy::class)->end();

    DB::connection('central')
        ->table('tenants')
        ->where('id', $this->tenantId)
        ->delete();
});

it('defaults platform admins into a tenant for tenant backed Filament resources', function (): void {
    $middleware = new FilamentTenantMiddleware();
    $method = new ReflectionMethod($middleware, 'resolveTenantId');
    $method->setAccessible(true);

    $request = adminTenantMiddlewareRequest(ListMultiSigWallets::class);
    $user = adminTenantMiddlewarePlatformAdmin();

    $tenantId = $method->invoke($middleware, $request, $user);

    expect($tenantId)->toBeString()
        ->and(Tenant::on('central')->whereKey($tenantId)->exists())->toBeTrue();
});

it('keeps platform admins in platform view for central Filament pages', function (): void {
    $middleware = new FilamentTenantMiddleware();
    $method = new ReflectionMethod($middleware, 'resolveTenantId');
    $method->setAccessible(true);

    $request = adminTenantMiddlewareRequest(\App\Filament\Admin\Pages\Dashboard::class);
    $user = adminTenantMiddlewarePlatformAdmin();

    expect($method->invoke($middleware, $request, $user))->toBeNull();
});

it('ignores a stored tenant when platform admins open central Filament pages', function (): void {
    $middleware = new FilamentTenantMiddleware();
    $method = new ReflectionMethod($middleware, 'resolveTenantId');
    $method->setAccessible(true);

    $request = adminTenantMiddlewareRequest(\App\Filament\Admin\Pages\Dashboard::class);
    $request->session()->put(FilamentTenantMiddleware::TENANT_SESSION_KEY, $this->tenantId);
    $user = adminTenantMiddlewarePlatformAdmin();

    expect($method->invoke($middleware, $request, $user))->toBeNull();
});

it('uses a stored tenant when platform admins open tenant backed Filament pages', function (): void {
    $middleware = new FilamentTenantMiddleware();
    $method = new ReflectionMethod($middleware, 'resolveTenantId');
    $method->setAccessible(true);

    $request = adminTenantMiddlewareRequest(ListMultiSigWallets::class);
    $request->session()->put(FilamentTenantMiddleware::TENANT_SESSION_KEY, $this->tenantId);
    $user = adminTenantMiddlewarePlatformAdmin();

    expect($method->invoke($middleware, $request, $user))->toBe($this->tenantId);
});


it('defaults admin panel users without teams into a tenant for tenant backed Filament resources', function (): void {
    $middleware = new FilamentTenantMiddleware();
    $method = new ReflectionMethod($middleware, 'resolveTenantId');
    $method->setAccessible(true);

    $request = adminTenantMiddlewareRequest(ListMultiSigWallets::class);
    $user = new class {
        public int $id = 2;

        public function hasRole(string $role): bool
        {
            return false;
        }

        public function hasPermission(string $permission): bool
        {
            return false;
        }
    };

    expect($method->invoke($middleware, $request, $user))->toBeString();
});

it('defaults admin panel users into a tenant for adjustment request pages', function (): void {
    $middleware = new FilamentTenantMiddleware();
    $method = new ReflectionMethod($middleware, 'resolveTenantId');
    $method->setAccessible(true);

    $request = adminTenantMiddlewareRequest(ListAdjustmentRequests::class);
    $user = adminTenantMiddlewarePlatformAdmin();

    expect($method->invoke($middleware, $request, $user))->toBeString();
});

function adminTenantMiddlewareRequest(string $actionClass): Request
{
    $request = Request::create('/admin/test', 'GET');
    $request->setLaravelSession(new Store('testing', new ArraySessionHandler(120)));
    $request->setRouteResolver(fn (): object => new class($actionClass) {
        public function __construct(private readonly string $actionClass) {}

        public function getActionName(): string
        {
            return $this->actionClass;
        }
    });

    return $request;
}

function adminTenantMiddlewarePlatformAdmin(): object
{
    return new class {
        public int $id = 1;

        public function hasRole(string $role): bool
        {
            return $role === 'platform_admin';
        }

        public function hasPermission(string $permission): bool
        {
            return false;
        }
    };
}
