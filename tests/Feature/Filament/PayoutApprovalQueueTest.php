<?php

use App\Models\User;
use Filament\Facades\Filament;
use function Pest\Livewire\livewire;

function setupFilamentPanel(): void
{
    $panel = Filament::getPanel('admin');
    if ($panel) {
        Filament::setCurrentPanel($panel);
        Filament::setServingStatus(true);
        $panel->boot();
    }
}

it('payout approval queue is accessible to finance-lead', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);
    setupFilamentPanel();

    livewire(\App\Filament\Admin\Pages\PayoutApprovalQueue::class)
        ->assertSuccessful();
});

it('support-l1 cannot access payout approval queue', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);
    setupFilamentPanel();

    livewire(\App\Filament\Admin\Pages\PayoutApprovalQueue::class)
        ->assertForbidden();
});
