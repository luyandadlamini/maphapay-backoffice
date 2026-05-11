<?php

declare(strict_types=1);

use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Models\User;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

it('allows only super-admin to minor-override approve via policy', function (): void {
    $sub = new CardSubscription();

    $super = User::factory()->create();
    $super->assignRole('super-admin');

    $ops = User::factory()->create();
    $ops->assignRole('operations-l2');

    expect($super->can('minorOverrideApprove', $sub))->toBeTrue();
    expect($ops->can('minorOverrideApprove', $sub))->toBeFalse();
});
