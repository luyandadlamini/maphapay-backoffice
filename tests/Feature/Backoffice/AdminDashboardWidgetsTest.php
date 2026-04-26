<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\AccountResource\Widgets\AccountStatsOverview;
use App\Filament\Admin\Support\AdminDashboardWidgets;
use App\Filament\Admin\Widgets\FailedMomoTransactionsWidget;
use App\Filament\Admin\Widgets\OperationsStatsOverview;
use App\Filament\Admin\Widgets\PrimaryBasketWidget;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('returns finance surface widgets for finance-lead', function (): void {
    $user = User::factory()->create();
    $user->assignRole('finance-lead');

    $widgets = app(AdminDashboardWidgets::class)->widgetsFor($user);

    expect($widgets)->toContain(PrimaryBasketWidget::class)
        ->and($widgets)->toContain(AccountStatsOverview::class)
        ->and($widgets)->toHaveCount(7);
});

it('returns operations surface widgets for support-l1', function (): void {
    $user = User::factory()->create();
    $user->assignRole('support-l1');

    $widgets = app(AdminDashboardWidgets::class)->widgetsFor($user);

    expect($widgets)->toBe([
        OperationsStatsOverview::class,
        FailedMomoTransactionsWidget::class,
    ]);
});

it('returns finance surface for super-admin', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super-admin');

    $widgets = app(AdminDashboardWidgets::class)->widgetsFor($user);

    expect($widgets)->toHaveCount(7)
        ->and($widgets[0])->toBe(PrimaryBasketWidget::class);
});

it('returns operations surface for compliance-manager', function (): void {
    $user = User::factory()->create();
    $user->assignRole('compliance-manager');

    $widgets = app(AdminDashboardWidgets::class)->widgetsFor($user);

    expect($widgets)->toBe([
        OperationsStatsOverview::class,
        FailedMomoTransactionsWidget::class,
    ]);
});

it('returns no widgets for user without workspace access', function (): void {
    $user = User::factory()->create();

    $widgets = app(AdminDashboardWidgets::class)->widgetsFor($user);

    expect($widgets)->toBe([]);
});

it('returns no widgets for null user', function (): void {
    expect(app(AdminDashboardWidgets::class)->widgetsFor(null))->toBe([]);
});
