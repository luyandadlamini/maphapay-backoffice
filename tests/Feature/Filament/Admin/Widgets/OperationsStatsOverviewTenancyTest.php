<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\OperationsStatsOverview;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Stancl\Tenancy\Tenancy;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setServingStatus(true);

    $tenantId = (string) Str::uuid();
    $databaseName = DB::connection('central')->getDatabaseName();

    DB::connection('central')
        ->table('tenants')
        ->update([
            'data' => json_encode([
                'tenancy_db_name' => $databaseName,
            ]),
        ]);

    DB::connection('central')->table('tenants')->insert([
        'id'            => $tenantId,
        'name'          => 'Operations widget tenant',
        'plan'          => 'default',
        'team_id'       => null,
        'trial_ends_at' => null,
        'created_at'    => now(),
        'updated_at'    => now(),
        'data'          => json_encode([
            'tenancy_db_name' => $databaseName,
        ]),
    ]);

    $this->tenantId = $tenantId;
});

afterEach(function (): void {
    app(Tenancy::class)->end();

    DB::connection('central')
        ->table('tenants')
        ->where('id', $this->tenantId)
        ->delete();
});

it('releases tenant context after counting tenant kyc documents', function (): void {
    expect(app(Tenancy::class)->initialized)->toBeFalse();

    $method = new ReflectionMethod(OperationsStatsOverview::class, 'getStats');
    $method->setAccessible(true);
    $method->invoke(new OperationsStatsOverview());

    expect(app(Tenancy::class)->initialized)->toBeFalse()
        ->and(Tenant::query()->whereKey($this->tenantId)->exists())->toBeTrue();
});
