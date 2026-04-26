<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('returns no active workspaces for a guest user', function (): void {
    $svc = new BackofficeWorkspaceAccess();

    expect($svc->activeWorkspaces(null))->toBe([]);
});

it('returns all workspaces in order for super-admin', function (): void {
    $user = User::factory()->create();
    $user->assignRole('super-admin');

    $svc = new BackofficeWorkspaceAccess();

    expect($svc->activeWorkspaces($user))->toBe(BackofficeWorkspaceAccess::ORDERED_WORKSPACES);
});

it('returns finance only for finance-lead', function (): void {
    $user = User::factory()->create();
    $user->assignRole('finance-lead');

    $svc = new BackofficeWorkspaceAccess();

    expect($svc->activeWorkspaces($user))->toBe(['finance']);
});

it('returns compliance and support for compliance-manager', function (): void {
    $user = User::factory()->create();
    $user->assignRole('compliance-manager');

    $svc = new BackofficeWorkspaceAccess();

    expect($svc->activeWorkspaces($user))->toBe(['compliance', 'support']);
});

it('returns support for support-l1', function (): void {
    $user = User::factory()->create();
    $user->assignRole('support-l1');

    $svc = new BackofficeWorkspaceAccess();

    expect($svc->activeWorkspaces($user))->toBe(['support']);
});

it('returns empty list for a user with no roles', function (): void {
    $user = User::factory()->create();

    $svc = new BackofficeWorkspaceAccess();

    expect($svc->activeWorkspaces($user))->toBe([]);
});
