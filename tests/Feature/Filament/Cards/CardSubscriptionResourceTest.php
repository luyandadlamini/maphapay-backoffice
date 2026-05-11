<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Cards\CardSubscriptionResource\Pages\ListCardSubscriptions;
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

it('renders card subscriptions list for operations-l2', function (): void {
    $user = User::factory()->create();
    $user->assignRole('operations-l2');
    $this->actingAs($user);

    livewire(ListCardSubscriptions::class)
        ->assertSuccessful();
});

it('renders card subscriptions list for support-l1', function (): void {
    $user = User::factory()->create();
    $user->assignRole('support-l1');
    $this->actingAs($user);

    livewire(ListCardSubscriptions::class)
        ->assertSuccessful();
});

it('forbids card subscriptions list without an allowed role', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(ListCardSubscriptions::class)
        ->assertForbidden();
});
