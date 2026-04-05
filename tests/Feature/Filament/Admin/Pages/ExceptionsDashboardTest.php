<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $panel = \Filament\Facades\Filament::getPanel('admin');
    if ($panel) {
        \Filament\Facades\Filament::setCurrentPanel($panel);
        \Filament\Facades\Filament::setServingStatus(true);
        $panel->boot();
    }
});

it('exceptions dashboard is accessible to operations-l2', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $ops = User::factory()->create();
    $ops->assignRole('operations-l2');
    actingAs($ops);

    livewire(\App\Filament\Admin\Pages\ExceptionsDashboard::class)
        ->assertSuccessful();
});

it('exceptions dashboard shows failed momo badge count', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $admin = User::factory()->create();
    $admin->assignRole('super-admin');
    actingAs($admin);

    livewire(\App\Filament\Admin\Pages\ExceptionsDashboard::class)
        ->assertSuccessful();
});
