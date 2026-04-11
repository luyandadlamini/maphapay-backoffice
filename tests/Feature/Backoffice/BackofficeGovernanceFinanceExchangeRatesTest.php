<?php

declare(strict_types=1);

use App\Domain\Asset\Models\ExchangeRate;
use App\Domain\Asset\Models\Asset;
use App\Domain\Compliance\Models\AuditLog;
use App\Filament\Admin\Resources\ExchangeRateResource;
use App\Filament\Admin\Resources\ExchangeRateResource\Pages\ListExchangeRates;
use App\Models\AdminActionApprovalRequest;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('limits exchange rate visibility to the finance workspace', function (): void {
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    expect(ExchangeRateResource::canViewAny())->toBeFalse();
    $this->get(ExchangeRateResource::getUrl())->assertForbidden();

    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    expect(ExchangeRateResource::canViewAny())->toBeTrue();
});

it('submits exchange rate set-rate changes for approval instead of mutating immediately', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    Asset::query()->updateOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'type' => 'fiat', 'precision' => 2, 'is_active' => true]);
    Asset::query()->updateOrCreate(['code' => 'ZAR'], ['name' => 'South African Rand', 'type' => 'fiat', 'precision' => 2, 'is_active' => true]);

    $rate = ExchangeRate::factory()->create([
        'from_asset_code' => 'USD',
        'to_asset_code' => 'ZAR',
        'rate' => '18.2500000000',
        'source' => ExchangeRate::SOURCE_MANUAL,
        'is_active' => true,
    ]);

    livewire(ListExchangeRates::class)
        ->callTableAction('setRate', $rate, data: [
            'rate' => '18.7500000000',
            'reason' => 'Treasury requested controlled FX repricing after market review.',
        ])
        ->assertHasNoTableActionErrors();

    expect($rate->fresh()?->rate)->toBe('18.2500000000');

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.exchange_rates.set_rate')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('finance')
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Treasury requested controlled FX repricing after market review.')
        ->and($request->target_type)->toBe(ExchangeRate::class)
        ->and($request->target_identifier)->toBe((string) $rate->getKey())
        ->and($request->payload['pair'] ?? null)->toBe('USD/ZAR')
        ->and($request->payload['old_rate'] ?? null)->toBe('18.2500000000')
        ->and($request->payload['requested_rate'] ?? null)->toBe('18.7500000000');
});

it('records governed audit metadata when refreshing an exchange rate', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    Asset::query()->updateOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'type' => 'fiat', 'precision' => 2, 'is_active' => true]);
    Asset::query()->updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'type' => 'fiat', 'precision' => 2, 'is_active' => true]);

    $rate = ExchangeRate::factory()->create([
        'from_asset_code' => 'USD',
        'to_asset_code' => 'EUR',
        'source' => ExchangeRate::SOURCE_API,
        'valid_at' => now()->subDays(2),
    ]);

    $previousValidAt = $rate->valid_at;

    livewire(ListExchangeRates::class)
        ->callTableAction('refresh', $rate, data: [
            'reason' => 'Ops refreshed the stale provider-fed FX quote after feed recovery.',
        ])
        ->assertHasNoTableActionErrors();

    expect($rate->fresh()?->valid_at?->gt($previousValidAt))->toBeTrue();

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.exchange_rates.refreshed')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('finance')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['reason'] ?? null)->toBe('Ops refreshed the stale provider-fed FX quote after feed recovery.')
        ->and($auditLog->metadata['pair'] ?? null)->toBe('USD/EUR')
        ->and($auditLog->metadata['source'] ?? null)->toBe(ExchangeRate::SOURCE_API);
});

it('submits bulk exchange rate status changes for approval with evidence', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $rates = ExchangeRate::factory()->count(2)->create([
        'source' => ExchangeRate::SOURCE_API,
        'is_active' => true,
    ]);

    livewire(ListExchangeRates::class)
        ->callTableBulkAction('deactivate', $rates, data: [
            'reason' => 'Pause provider-fed rates pending treasury discrepancy review.',
        ])
        ->assertHasNoTableActionErrors();

    expect($rates->map(fn (ExchangeRate $rate) => $rate->fresh()?->is_active)->all())->toBe([true, true]);

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.exchange_rates.bulk_deactivate')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('finance')
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Pause provider-fed rates pending treasury discrepancy review.')
        ->and($request->payload['requested_state'] ?? null)->toBe('inactive')
        ->and($request->payload['record_count'] ?? null)->toBe(2)
        ->and($request->payload['pairs'] ?? null)->toHaveCount(2);
});

it('blocks direct create and edit capabilities for exchange rates', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $rate = ExchangeRate::factory()->create();

    expect(ExchangeRateResource::canCreate())->toBeFalse()
        ->and(ExchangeRateResource::canEdit($rate))->toBeFalse();
});
