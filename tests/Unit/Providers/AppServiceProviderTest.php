<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\WaterlineServiceProvider;
use Illuminate\Foundation\Application;
use Tests\UnitTestCase;

uses(UnitTestCase::class);

beforeEach(function () {
    $this->app = Mockery::mock(Application::class);
    $this->provider = new AppServiceProvider($this->app);

    // Add flush method expectation for tearDown
    $this->app->shouldReceive('flush')->andReturnNull();
});

it('can instantiate app service provider', function () {
    expect($this->provider)->toBeInstanceOf(AppServiceProvider::class);
});

it('registers WaterlineServiceProvider in non-testing environment', function () {
    // Mock non-testing environment
    $this->app->shouldReceive('environment')->once()->andReturn('production');

    // Expect the WaterlineServiceProvider to be registered
    $this->app->shouldReceive('register')->once()->with(WaterlineServiceProvider::class);

    // Expect the BlockchainServiceProvider to be registered
    $this->app->shouldReceive('register')->once()->with(App\Providers\BlockchainServiceProvider::class);

    // Expect strategy and verifier bindings
    $this->app->shouldReceive('bind')->times(4);
    $this->app->shouldReceive('singleton')->twice();

    $this->provider->register();
});

it('does not register WaterlineServiceProvider in testing environment', function () {
    // Mock testing environment
    $this->app->shouldReceive('environment')->once()->andReturn('testing');

    // Should not call register for WaterlineServiceProvider, but should register BlockchainServiceProvider
    $this->app->shouldReceive('register')->once()->with(App\Providers\BlockchainServiceProvider::class);
    $this->app->shouldNotReceive('register')->with(WaterlineServiceProvider::class);

    // Expect strategy and verifier bindings in testing environment too
    $this->app->shouldReceive('bind')->times(4);
    $this->app->shouldReceive('singleton')->twice();

    $this->provider->register();
});

it('has boot method that can be called', function () {
    // Mock callbacks and environment checks used in boot()
    $this->app->shouldReceive('resolving')->once()->with(L5Swagger\GeneratorFactory::class, Mockery::type(Closure::class));
    $this->app->shouldReceive('environment')->with('demo')->andReturn(false);
    $this->app->shouldReceive('bound')->with('request')->andReturn(false);

    // Test that boot method exists and can be called without errors
    expect(function () {
        $this->provider->boot();
    })->not->toThrow(Exception::class);
});
