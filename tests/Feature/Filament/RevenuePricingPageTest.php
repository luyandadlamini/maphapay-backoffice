<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\RevenuePricingPage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('allows finance-lead to access revenue pricing page', function (): void {
    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    expect(RevenuePricingPage::canAccess())->toBeTrue();

    $this->get(RevenuePricingPage::getUrl())
        ->assertOk()
        ->assertSee(__('Save fee settings'));
});

it('allows super-admin to access revenue pricing page and see platform settings link', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super-admin');
    $this->actingAs($user);

    expect(RevenuePricingPage::canAccess())->toBeTrue();

    $this->get(RevenuePricingPage::getUrl())
        ->assertOk()
        ->assertSee(__('Open Platform Settings'));
});

it('forbids support-l1 from accessing revenue pricing page', function (): void {
    $user = User::factory()->create();
    $user->assignRole('support-l1');
    $this->actingAs($user);

    expect(RevenuePricingPage::canAccess())->toBeFalse();

    $this->get(RevenuePricingPage::getUrl())->assertForbidden();
});

it('requires governance reason when saving fee settings', function (): void {
    $user = User::factory()->create();
    $user->assignRole('finance-lead');

    Livewire::actingAs($user)
        ->test(RevenuePricingPage::class)
        ->call('saveFees')
        ->assertHasErrors(['governanceReason']);
});
