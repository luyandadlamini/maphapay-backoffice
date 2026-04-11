<?php

declare(strict_types=1);

use App\Domain\Mobile\Models\Pocket;
use App\Filament\Admin\Resources\UserResource\Pages\ViewUser;
use App\Filament\Admin\Resources\UserResource\RelationManagers\PocketsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('renders the savings pockets relation manager on the user view page', function (): void {
    $admin = User::factory()->withAdminRole()->create();
    $this->actingAs($admin);

    $customer = User::factory()->create();

    $pocket = Pocket::query()->create([
        'user_uuid'      => $customer->uuid,
        'name'           => 'Rainy Day',
        'target_amount'  => 5000.00,
        'current_amount' => 1250.00,
        'category'       => Pocket::CATEGORY_EMERGENCY,
        'color'          => '#4F8CFF',
        'is_completed'   => false,
    ]);

    livewire(PocketsRelationManager::class, [
        'ownerRecord' => $customer,
        'pageClass'   => ViewUser::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$pocket]);
});
