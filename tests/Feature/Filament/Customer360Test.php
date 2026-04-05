<?php

declare(strict_types=1);

use App\Models\User;

it('customer 360 page loads for operations-l2', function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $ops = User::factory()->create();
    $ops->assignRole('operations-l2');
    $this->actingAs($ops);

    $customer = User::factory()->create();

    $response = $this->get(
        \App\Filament\Admin\Resources\UserResource::getUrl('view', ['record' => $customer->id])
    );

    $response->assertSuccessful();
});
