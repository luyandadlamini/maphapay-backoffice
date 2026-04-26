<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\RevenueTarget;
use App\Filament\Admin\Resources\RevenueTargetResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    if (! Schema::hasTable('revenue_targets')) {
        Artisan::call(
            'migrate',
            [
                '--path'  => 'database/migrations/tenant/2026_04_24_120000_create_revenue_targets_table.php',
                '--force' => true,
            ]
        );
    }

    $this->seed(RolesAndPermissionsSeeder::class);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('allows finance-lead to access revenue targets index', function (): void {
    $user = User::factory()->create();
    $user->assignRole('finance-lead');
    $this->actingAs($user);

    expect(RevenueTargetResource::canAccess())->toBeTrue();

    $this->get(RevenueTargetResource::getUrl())->assertOk();
});

it('forbids support-l1 from accessing revenue targets', function (): void {
    $user = User::factory()->create();
    $user->assignRole('support-l1');
    $this->actingAs($user);

    expect(RevenueTargetResource::canAccess())->toBeFalse();

    $this->get(RevenueTargetResource::getUrl())->assertForbidden();
});

it('blocks support-l1 from creating a revenue target via policy', function (): void {
    $user = User::factory()->create();
    $user->assignRole('support-l1');

    expect($user->can('create', RevenueTarget::class))->toBeFalse();
});

it('allows finance-lead to create revenue targets via policy', function (): void {
    $user = User::factory()->create();
    $user->assignRole('finance-lead');

    expect($user->can('create', RevenueTarget::class))->toBeTrue();
});
