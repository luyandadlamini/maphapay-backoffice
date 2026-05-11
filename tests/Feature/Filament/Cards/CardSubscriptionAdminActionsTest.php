<?php

declare(strict_types=1);

use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Models\User;

it('allows operations-l2 to suspend subscriptions via policy', function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    $user = User::factory()->create();
    $user->assignRole('operations-l2');
    $sub = new CardSubscription();

    expect($user->can('suspend', $sub))->toBeTrue();
});

it('denies support-l1 subscription suspend via policy', function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    $user = User::factory()->create();
    $user->assignRole('support-l1');
    $sub = new CardSubscription();

    expect($user->can('suspend', $sub))->toBeFalse();
});
