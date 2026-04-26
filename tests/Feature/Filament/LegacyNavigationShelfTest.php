<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\BridgeTransactionResource;
use App\Filament\Admin\Resources\CertificateResource;
use App\Filament\Admin\Resources\DeFiPositionResource;
use App\Filament\Admin\Resources\GcuVotingProposalResource;
use App\Filament\Admin\Resources\OrderBookResource;
use App\Filament\Admin\Resources\OrderResource;
use App\Filament\Admin\Resources\PollResource;
use App\Filament\Admin\Resources\VirtualsAgentResource;
use App\Filament\Admin\Resources\VoteResource;
use App\Filament\Admin\Support\LegacyAdminNavigation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();

    config([
        'maphapay.show_legacy_admin_nav' => false,
        'brand.admin_modules'            => null,
    ]);
});

/** @return array<int, class-string<\Filament\Resources\Resource>> */
function legacyShelfResourceClasses(): array
{
    return [
        OrderResource::class,
        OrderBookResource::class,
        DeFiPositionResource::class,
        BridgeTransactionResource::class,
        PollResource::class,
        VoteResource::class,
        GcuVotingProposalResource::class,
        CertificateResource::class,
        VirtualsAgentResource::class,
    ];
}

it('hides legacy shelf resources from support-l1 when legacy nav is disabled', function (): void {
    $user = User::factory()->create();
    $user->assignRole('support-l1');
    $this->actingAs($user);

    foreach (legacyShelfResourceClasses() as $resourceClass) {
        expect($resourceClass::shouldRegisterNavigation())->toBeFalse();
    }
});

it('shows legacy shelf resources for super-admin when legacy nav is disabled', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super-admin');
    $this->actingAs($user);

    foreach (legacyShelfResourceClasses() as $resourceClass) {
        expect($resourceClass::shouldRegisterNavigation())->toBeTrue();
    }
});

it('shows legacy shelf resources for support-l1 when legacy nav is enabled and admin_modules is null', function (): void {
    config(['maphapay.show_legacy_admin_nav' => true]);

    $user = User::factory()->create();
    $user->assignRole('support-l1');
    $this->actingAs($user);

    foreach (legacyShelfResourceClasses() as $resourceClass) {
        expect($resourceClass::shouldRegisterNavigation())->toBeTrue();
    }
});

it('hides legacy shelf resources when legacy nav is enabled but legacy group is not in admin_modules', function (): void {
    config(
        [
            'maphapay.show_legacy_admin_nav' => true,
            'brand.admin_modules'            => ['Transactions', 'Customers'],
        ]
    );

    $user = User::factory()->create();
    $user->assignRole('support-l1');
    $this->actingAs($user);

    foreach (legacyShelfResourceClasses() as $resourceClass) {
        expect($resourceClass::shouldRegisterNavigation())->toBeFalse();
    }
});

it('shows legacy shelf resources when legacy group is listed in admin_modules', function (): void {
    config(
        [
            'maphapay.show_legacy_admin_nav' => true,
            'brand.admin_modules'            => ['Transactions', LegacyAdminNavigation::NAVIGATION_GROUP],
        ]
    );

    $user = User::factory()->create();
    $user->assignRole('support-l1');
    $this->actingAs($user);

    foreach (legacyShelfResourceClasses() as $resourceClass) {
        expect($resourceClass::shouldRegisterNavigation())->toBeTrue();
    }
});
