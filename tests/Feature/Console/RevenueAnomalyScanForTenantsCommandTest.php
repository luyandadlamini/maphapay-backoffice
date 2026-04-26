<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\RevenueTarget;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Tenancy;
use Tests\Support\TenantDatabasePrivileges;
use Tests\TestCase;

beforeEach(function (): void {
    $tenancy = app(Tenancy::class);
    if ($tenancy->initialized) {
        $tenancy->end();
    }
});

afterEach(function (): void {
    $tenancy = app(Tenancy::class);
    if ($tenancy->initialized) {
        $tenancy->end();
    }
});

/**
 * Provisions a stancl tenant database (CREATE DATABASE). Call only when
 * {@see TestCase::canCreateTenantDatabases()} is true.
 *
 * Forces central-connection writes for User and Team so that tenancy
 * does not redirect them into a tenant database that lacks a users table.
 */
function revenueAnomalyScan_makeTenantForTest(): Tenant
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['user_id' => $owner->id]);

    return Tenant::createFromTeam($team);
}

function revenueAnomalyScan_ensureRevenueTargetsTable(): void
{
    if (! Schema::hasTable('revenue_targets')) {
        Artisan::call(
            'migrate',
            [
                '--path'  => 'database/migrations/tenant/2026_04_24_120000_create_revenue_targets_table.php',
                '--force' => true,
            ]
        );
    }

    if (! Schema::hasColumn('revenue_targets', 'deleted_at')) {
        Artisan::call(
            'migrate',
            [
                '--path'  => 'database/migrations/2026_04_26_000000_add_deleted_at_to_revenue_targets_table.php',
                '--force' => true,
            ]
        );
    }
}

it('scans a single tenant and detects a non-positive revenue target', function (): void {
    if (! TenantDatabasePrivileges::canCreateTenantDatabases()) {
        $this->markTestSkipped(
            'Requires CREATE DATABASE for stancl tenant DBs. Local: run scripts/reset-local-mysql-test-access.sh (grants CREATE, DROP on *.* to the test user). CI: feature job uses MySQL root.'
        );
    }

    tenancy()->end();

    $tenant = revenueAnomalyScan_makeTenantForTest();

    app(Tenancy::class)->initialize($tenant);
    revenueAnomalyScan_ensureRevenueTargetsTable();

    RevenueTarget::query()->create([
        'period_month' => '2031-06',
        'stream_code'  => 'p2p_send',
        'amount'       => '0.00',
        'currency'     => 'ZAR',
        'notes'        => null,
    ]);

    app(Tenancy::class)->end();

    $exit = Artisan::call('revenue:scan-anomalies:for-tenants', [
        '--tenant' => $tenant->id,
    ]);

    expect($exit)->toBe(0);
});

it('sends database notifications for a tenant scan when --notify is passed', function (): void {
    if (! TenantDatabasePrivileges::canCreateTenantDatabases()) {
        $this->markTestSkipped(
            'Requires CREATE DATABASE for stancl tenant DBs. Local: run scripts/reset-local-mysql-test-access.sh (grants CREATE, DROP on *.* to the test user). CI: feature job uses MySQL root.'
        );
    }

    tenancy()->end();

    $this->seed(RolesAndPermissionsSeeder::class);

    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');

    $tenant = revenueAnomalyScan_makeTenantForTest();

    app(Tenancy::class)->initialize($tenant);
    revenueAnomalyScan_ensureRevenueTargetsTable();

    RevenueTarget::query()->create([
        'period_month' => '2031-07',
        'stream_code'  => 'mcard',
        'amount'       => '-5.00',
        'currency'     => 'ZAR',
        'notes'        => null,
    ]);

    app(Tenancy::class)->end();

    Artisan::call('revenue:scan-anomalies:for-tenants', [
        '--tenant' => $tenant->id,
        '--notify' => true,
    ]);

    $finance->refresh();

    expect($finance->notifications()->count())->toBeGreaterThan(0);
});
