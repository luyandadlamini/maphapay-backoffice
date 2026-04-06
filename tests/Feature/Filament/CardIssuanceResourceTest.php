<?php

use App\Domain\CardIssuance\Models\Card;
use App\Filament\Admin\Resources\CardIssuanceResource\Pages\ListCardIssuances;
use App\Models\User;
use Filament\Facades\Filament;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('compliance-manager can access card issuance resource', function () {
    $compliance = User::factory()->create();
    $compliance->assignRole('compliance-manager');
    $this->actingAs($compliance);

    livewire(ListCardIssuances::class)
        ->assertSuccessful();
});

it('support-l1 cannot access card issuance resource', function () {
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    livewire(ListCardIssuances::class)
        ->assertForbidden();
});
