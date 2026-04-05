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

it('compliance-manager can access kyc documents in Compliance group', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $compliance = User::factory()->create();
    $compliance->assignRole('compliance-manager');
    actingAs($compliance);

    livewire(\App\Filament\Admin\Resources\KycDocumentResource\Pages\ListKycDocuments::class)
        ->assertSuccessful();
});
