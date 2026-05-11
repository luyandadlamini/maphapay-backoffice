<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\CardsDashboard;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('renders cards operations dashboard for super-admin', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super-admin');
    $this->actingAs($user);

    livewire(CardsDashboard::class)
        ->assertSuccessful();
});
