<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Widgets\PrimaryBasketWidget;
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

it('includes treasury widgets on dashboard for finance-lead', function (): void {
    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    $page = new Dashboard();
    $visible = $page->getVisibleWidgets();

    expect($visible)->toContain(PrimaryBasketWidget::class);
});

it('excludes treasury widgets on dashboard for support-l1', function (): void {
    $user = User::factory()->create();
    $user->assignRole('support-l1');
    $this->actingAs($user);

    $page = new Dashboard();
    $visible = $page->getVisibleWidgets();

    expect($visible)->not->toContain(PrimaryBasketWidget::class);
});
