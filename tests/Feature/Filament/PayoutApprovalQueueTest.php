<?php

declare(strict_types=1);

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

it('payout approval queue is accessible to finance-lead', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    livewire(App\Filament\Admin\Pages\PayoutApprovalQueue::class)
        ->assertSuccessful();
});

it('support-l1 cannot access payout approval queue', function (): void {
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    livewire(App\Filament\Admin\Pages\PayoutApprovalQueue::class)
        ->assertForbidden();
});
