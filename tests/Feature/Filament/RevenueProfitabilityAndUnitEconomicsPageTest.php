<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\RevenueProfitabilityPage;
use App\Filament\Admin\Pages\RevenueUnitEconomicsPage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('allows finance-lead to access profitability and unit economics pages', function (): void {
    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    expect(RevenueProfitabilityPage::canAccess())->toBeTrue()
        ->and(RevenueUnitEconomicsPage::canAccess())->toBeTrue();

    $this->get(RevenueProfitabilityPage::getUrl())
        ->assertOk()
        ->assertSee(__('Margin bridge'))
        ->assertSee(__('Cost of revenue (COR)'))
        ->assertSee(__('Blocked — COR inputs not connected'));

    $this->get(RevenueUnitEconomicsPage::getUrl())
        ->assertOk()
        ->assertSee(__('Unit economics'))
        ->assertSee(__('CAC (blended)'))
        ->assertSee(__('Not connected'))
        ->assertSee(__('We intentionally do not display sample CAC, LTV, or ratio charts here.'));
});

it('shows awaiting COR snapshot when cor bridge flag is enabled but port has no data', function (): void {
    config(['maphapay.revenue_cor_bridge_enabled' => true]);

    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    $this->get(RevenueProfitabilityPage::getUrl())
        ->assertOk()
        ->assertSee(__('Awaiting first COR snapshot'))
        ->assertDontSee(__('Blocked — COR inputs not connected'));
});

it('shows awaiting CAC/LTV snapshot when unit economics flag is enabled but port has no data', function (): void {
    config(['maphapay.revenue_unit_economics_enabled' => true]);

    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    $this->get(RevenueUnitEconomicsPage::getUrl())
        ->assertOk()
        ->assertSee(__('Awaiting first CAC/LTV snapshot'))
        ->assertDontSee(__('Not connected'));
});

it('shows stub COR values and live badge when stub reader and feature flag are on', function (): void {
    config([
        'maphapay.revenue_cor_bridge_enabled'     => true,
        'maphapay.revenue_cor_bridge_stub_reader' => true,
    ]);

    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    $this->get(RevenueProfitabilityPage::getUrl())
        ->assertOk()
        ->assertSee(__('Live data'))
        ->assertSee('ZAR 12,345.67')
        ->assertSee(__('STUB — not finance data'));
});

it('shows stub unit economics values and live badge when stub reader and feature flag are on', function (): void {
    config([
        'maphapay.revenue_unit_economics_enabled'     => true,
        'maphapay.revenue_unit_economics_stub_reader' => true,
    ]);

    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    $this->get(RevenueUnitEconomicsPage::getUrl())
        ->assertOk()
        ->assertSee(__('Live data'))
        ->assertSee('ZAR 42.00')
        ->assertSee(__('STUB — not finance data'));
});

it('allows super-admin to access profitability and unit economics pages', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super-admin');
    $this->actingAs($user);

    expect(RevenueProfitabilityPage::canAccess())->toBeTrue()
        ->and(RevenueUnitEconomicsPage::canAccess())->toBeTrue();

    $this->get(RevenueProfitabilityPage::getUrl())->assertOk();
    $this->get(RevenueUnitEconomicsPage::getUrl())->assertOk();
});

it('forbids support-l1 from accessing profitability and unit economics pages', function (): void {
    $user = User::factory()->create();
    $user->assignRole('support-l1');
    $this->actingAs($user);

    expect(RevenueProfitabilityPage::canAccess())->toBeFalse()
        ->and(RevenueUnitEconomicsPage::canAccess())->toBeFalse();

    $this->get(RevenueProfitabilityPage::getUrl())->assertForbidden();
    $this->get(RevenueUnitEconomicsPage::getUrl())->assertForbidden();
});
