<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\RevenueTarget;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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
});

it('completes revenue anomaly scan and logs lifecycle', function (): void {
    Log::spy();

    $exit = Artisan::call('revenue:scan-anomalies');

    expect($exit)->toBe(0);

    Log::shouldHaveReceived('info')->withArgs(
        static fn (mixed $message, mixed $context): bool => $message === 'revenue_anomaly_scan.start'
    )->once();

    Log::shouldHaveReceived('info')->withArgs(
        static fn (mixed $message, mixed $context): bool => $message === 'revenue_anomaly_scan.complete'
            && is_array($context)
            && ($context['anomaly_found'] ?? null) === false
    )->once();
});

it('logs warning when a non-positive revenue target exists', function (): void {
    Log::spy();

    RevenueTarget::query()->create([
        'period_month' => '2030-01',
        'stream_code'  => 'p2p_send',
        'amount'       => '0.00',
        'currency'     => 'ZAR',
        'notes'        => null,
    ]);

    $exit = Artisan::call('revenue:scan-anomalies');

    expect($exit)->toBe(0);

    Log::shouldHaveReceived('warning')->withArgs(
        static fn (mixed $message, mixed $context = null): bool => $message === 'revenue_anomaly_scan.zero_or_negative_target_detected'
    )->once();

    Log::shouldHaveReceived('info')->withArgs(
        static fn (mixed $message, mixed $context): bool => $message === 'revenue_anomaly_scan.complete'
            && is_array($context)
            && ($context['anomaly_found'] ?? null) === true
    )->once();
});

it('sends database notifications when --notify is passed', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');

    RevenueTarget::query()->create([
        'period_month' => '2030-02',
        'stream_code'  => 'mcard',
        'amount'       => '-1.00',
        'currency'     => 'ZAR',
        'notes'        => null,
    ]);

    Artisan::call('revenue:scan-anomalies', ['--notify' => true]);

    expect($finance->notifications()->count())->toBeGreaterThan(0);
});
