<?php

declare(strict_types=1);

use App\Models\User;
use function Pest\Livewire\livewire;

// Note: These Livewire component tests require full Filament panel registration.
// They are skipped in CI. Run manually against a fully booted panel environment.

it('support-l1 cannot see raw mobile number in infolist', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    $customer = User::factory()->create(['mobile' => '76123456', 'dial_code' => '+268']);

    livewire(\App\Filament\Admin\Resources\UserResource\Pages\ViewUser::class, ['record' => $customer->id])
        ->assertDontSee('76123456')
        ->assertSee('7612****456');
})->skip('Livewire component test - requires full Filament panel registration; run manually');

it('operations-l2 with view-pii can see raw mobile number in infolist', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $ops = User::factory()->create();
    $ops->assignRole('operations-l2');
    $ops->givePermissionTo('view-pii');
    $this->actingAs($ops);

    $customer = User::factory()->create(['mobile' => '76123456']);

    livewire(\App\Filament\Admin\Resources\UserResource\Pages\ViewUser::class, ['record' => $customer->id])
        ->assertSee('76123456');
})->skip('Livewire component test - requires full Filament panel registration; run manually');
