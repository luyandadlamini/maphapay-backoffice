<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\RevenueTarget;
use App\Filament\Admin\Pages\RevenuePerformanceOverview;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('allows finance-lead to access revenue performance overview', function (): void {
    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    expect(RevenuePerformanceOverview::canAccess())->toBeTrue()
        ->and($this->get(RevenuePerformanceOverview::getUrl())->assertOk());
});

it('allows super-admin to access revenue performance overview', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super-admin');
    $this->actingAs($user);

    expect(RevenuePerformanceOverview::canAccess())->toBeTrue()
        ->and($this->get(RevenuePerformanceOverview::getUrl())->assertOk());
});

it('forbids support-l1 from accessing revenue performance overview', function (): void {
    $user = User::factory()->create();
    $user->assignRole('support-l1');
    $this->actingAs($user);

    expect(RevenuePerformanceOverview::canAccess())->toBeFalse()
        ->and($this->get(RevenuePerformanceOverview::getUrl())->assertForbidden());
});

it('forbids compliance-manager from accessing revenue performance overview', function (): void {
    $user = User::factory()->create();
    $user->assignRole('compliance-manager');
    $this->actingAs($user);

    expect(RevenuePerformanceOverview::canAccess())->toBeFalse()
        ->and($this->get(RevenuePerformanceOverview::getUrl())->assertForbidden());
});

it('shows time range activity disclaimer kpis trend placeholder and targets strip', function (): void {
    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    $this->get(RevenuePerformanceOverview::getUrl())
        ->assertOk()
        ->assertSee(__('Time range'))
        ->assertSee(__('Activity snapshot'))
        ->assertSee(__('Mapped rows'))
        ->assertSee(__('Trend'))
        ->assertSee(__('Targets needing review'))
        ->assertSee(__('Reporting currency'));
});

it('surfaces non-positive revenue targets in the anomalies table', function (): void {
    if (! Schema::hasTable('revenue_targets')) {
        Artisan::call(
            'migrate',
            [
                '--path'  => 'database/migrations/tenant/2026_04_24_120000_create_revenue_targets_table.php',
                '--force' => true,
            ]
        );
    }

    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    RevenueTarget::query()->create([
        'period_month' => '2026-01',
        'stream_code'  => 'p2p_send',
        'amount'       => '0.00',
        'currency'     => 'ZAR',
        'notes'        => 'test anomaly row',
    ]);

    $this->get(RevenuePerformanceOverview::getUrl())
        ->assertOk()
        ->assertSee('2026-01')
        ->assertSee('p2p_send');
});
