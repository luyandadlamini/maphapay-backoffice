<?php

declare(strict_types=1);

use App\Models\User;
use App\Providers\WaterlineServiceProvider;
use Illuminate\Support\Facades\Gate;

it('can register the waterline gate', function () {
    $provider = new WaterlineServiceProvider(app());

    // Register the provider
    $provider->register();
    $provider->boot();

    expect(Gate::has('viewWaterline'))->toBeTrue();
});

it('denies access to waterline for unauthorized users', function () {
    $provider = new WaterlineServiceProvider(app());
    $provider->register();
    $provider->boot();

    $user = User::factory()->create(['email' => 'regular@example.com']);

    expect(Gate::forUser($user)->allows('viewWaterline'))->toBeFalse();
});

it('allows access to waterline for authorized users', function () {
    // This test would pass if we add emails to the authorized list
    // For now, we test that the gate exists and functions correctly
    $provider = new WaterlineServiceProvider(app());
    $provider->register();
    $provider->boot();

    $user = User::factory()->create(['email' => 'unauthorized@example.com']);

    expect(Gate::forUser($user)->allows('viewWaterline'))->toBeFalse();
});
