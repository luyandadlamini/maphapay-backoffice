<?php

declare(strict_types=1);

use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Filament\Admin\Resources\Cards\CardDisputeResource\Pages\ListCardDisputes;
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

it('renders disputes list for fraud-analyst', function (): void {
    $user = User::factory()->create();
    $user->assignRole('fraud-analyst');
    $this->actingAs($user);

    livewire(ListCardDisputes::class)
        ->assertSuccessful();
});

it('allows fraud-analyst to update disputes via policy', function (): void {
    $user = User::factory()->create();
    $user->assignRole('fraud-analyst');
    $dispute = new CardDispute();

    expect($user->can('update', $dispute))->toBeTrue();
});
