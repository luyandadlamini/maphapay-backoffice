<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\ExchangeRate;
use App\Domain\Compliance\Models\AuditLog;
use App\Filament\Admin\Resources\AssetResource;
use App\Filament\Admin\Resources\AssetResource\Pages\ListAssets;
use App\Filament\Admin\Resources\AssetResource\Pages\ViewAsset;
use App\Filament\Admin\Resources\AssetResource\RelationManagers\AccountBalancesRelationManager;
use App\Filament\Admin\Resources\AssetResource\RelationManagers\ExchangeRatesRelationManager;
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

it('limits asset visibility to the finance workspace', function (): void {
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    expect(AssetResource::canViewAny())->toBeFalse();
    $this->get(AssetResource::getUrl())->assertForbidden();

    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    expect(AssetResource::canViewAny())->toBeTrue();
});

it('submits asset edit requests for approval instead of mutating immediately', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $asset = Asset::query()->updateOrCreate(['code' => 'SZL'], [
        'code'      => 'SZL',
        'name'      => 'Swazi Lilangeni',
        'precision' => 2,
        'type'      => 'fiat',
        'is_active' => true,
        'metadata'  => ['symbol' => 'E'],
    ]);

    livewire(ListAssets::class)
        ->callTableAction('requestEdit', $asset, data: [
            'name'      => 'Swazi Lilangeni Treasury Unit',
            'type'      => 'fiat',
            'symbol'    => 'SZL$',
            'precision' => 4,
            'is_active' => false,
            'metadata'  => [
                'symbol'    => 'SZL$',
                'regulated' => true,
            ],
            'reason' => 'Treasury requested a controlled asset metadata update after policy review.',
        ])
        ->assertHasNoTableActionErrors();

    expect($asset->fresh())
        ->name->toBe('Swazi Lilangeni')
        ->precision->toBe(2)
        ->is_active->toBeTrue();

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.assets.edit')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('finance')
        ->and($request->status)->toBe('pending')
        ->and($request->reason)->toBe('Treasury requested a controlled asset metadata update after policy review.')
        ->and($request->target_type)->toBe(Asset::class)
        ->and($request->target_identifier)->toBe('SZL')
        ->and($request->payload['asset_code'] ?? null)->toBe('SZL')
        ->and($request->payload['old_values']['precision'] ?? null)->toBe(2)
        ->and($request->payload['requested_values']['precision'] ?? null)->toBe(4)
        ->and($request->payload['requested_values']['symbol'] ?? null)->toBe('SZL$')
        ->and($request->payload['requested_values']['is_active'] ?? null)->toBeFalse();
});

it('submits asset status changes for approval from the view page', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $asset = Asset::query()->updateOrCreate(['code' => 'XSZ'], [
        'code'      => 'XSZ',
        'name'      => 'Experimental Stable Zone',
        'type'      => 'crypto',
        'precision' => 8,
        'is_active' => true,
        'metadata'  => ['symbol' => 'XSZ'],
    ]);

    livewire(ViewAsset::class, ['record' => $asset->getKey()])
        ->callAction('toggle_status', data: [
            'reason' => 'Pause the asset while finance reviews settlement readiness controls.',
        ])
        ->assertHasNoActionErrors();

    expect($asset->fresh()?->is_active)->toBeTrue();

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.assets.deactivate')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('finance')
        ->and($request->reason)->toBe('Pause the asset while finance reviews settlement readiness controls.')
        ->and($request->payload['asset_code'] ?? null)->toBe('XSZ')
        ->and($request->payload['current_state'] ?? null)->toBe('active')
        ->and($request->payload['requested_state'] ?? null)->toBe('inactive');
});

it('submits bulk asset status changes for approval with evidence', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $assets = Asset::factory()->count(2)->active()->create();

    livewire(ListAssets::class)
        ->callTableBulkAction('deactivate', $assets, data: [
            'reason' => 'Suspend these assets pending treasury and reconciliation sign-off.',
        ])
        ->assertHasNoTableActionErrors();

    expect($assets->map(fn (Asset $asset) => $asset->fresh()?->is_active)->all())->toBe([true, true]);

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.assets.bulk_deactivate')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('finance')
        ->and($request->status)->toBe('pending')
        ->and($request->payload['requested_state'] ?? null)->toBe('inactive')
        ->and($request->payload['record_count'] ?? null)->toBe(2)
        ->and($request->payload['asset_codes'] ?? null)->toHaveCount(2);
});

it('applies exchange-rate relation-manager governance under the finance workspace', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $asset = Asset::query()->updateOrCreate(['code' => 'USD'], [
        'code'      => 'USD',
        'name'      => 'US Dollar',
        'type'      => 'fiat',
        'precision' => 2,
        'is_active' => true,
        'metadata'  => ['symbol' => '$'],
    ]);

    Asset::query()->updateOrCreate(['code' => 'EUR'], [
        'name'      => 'Euro',
        'type'      => 'fiat',
        'precision' => 2,
        'is_active' => true,
    ]);

    $rate = ExchangeRate::factory()->create([
        'from_asset_code' => 'USD',
        'to_asset_code'   => 'EUR',
        'source'          => ExchangeRate::SOURCE_API,
        'valid_at'        => now()->subDay(),
        'is_active'       => true,
    ]);

    $previousValidAt = $rate->valid_at;

    livewire(ExchangeRatesRelationManager::class, [
        'ownerRecord' => $asset,
        'pageClass'   => ViewAsset::class,
    ])
        ->callTableAction('refresh', $rate, data: [
            'reason' => 'Refresh the stale related FX quote after upstream feed recovery.',
        ])
        ->assertHasNoTableActionErrors()
        ->callTableAction('deactivate', $rate, data: [
            'reason' => 'Disable the related FX pair pending treasury discrepancy review.',
        ])
        ->assertHasNoTableActionErrors();

    expect($rate->fresh()?->valid_at?->gt($previousValidAt))->toBeTrue()
        ->and($rate->fresh()?->is_active)->toBeTrue();

    $auditLog = AuditLog::query()
        ->where('action', 'backoffice.exchange_rates.refreshed')
        ->latest('id')
        ->first();

    expect($auditLog)->not->toBeNull()
        ->and($auditLog->metadata['workspace'] ?? null)->toBe('finance')
        ->and($auditLog->metadata['mode'] ?? null)->toBe('direct_elevated')
        ->and($auditLog->metadata['pair'] ?? null)->toBe('USD/EUR')
        ->and($auditLog->metadata['context'] ?? null)->toBe('asset_relation_manager');

    $request = AdminActionApprovalRequest::query()
        ->where('action', 'backoffice.exchange_rates.deactivate')
        ->latest('id')
        ->first();

    expect($request)->not->toBeNull()
        ->and($request->workspace)->toBe('finance')
        ->and($request->payload['pair'] ?? null)->toBe('USD/EUR')
        ->and($request->payload['context'] ?? null)->toBe('asset_relation_manager')
        ->and($request->payload['requested_state'] ?? null)->toBe('inactive');
});

it('blocks direct asset and account-balance mutation bypasses', function (): void {
    $finance = User::factory()->create();
    $finance->assignRole('finance-lead');
    $this->actingAs($finance);

    $asset = Asset::factory()->create();
    $accountUser = User::factory()->create();
    $account = Account::factory()->create(['user_uuid' => $accountUser->uuid]);
    $balance = AccountBalance::factory()
        ->forAsset($asset)
        ->forAccount($account)
        ->create();

    expect(AssetResource::canEdit($asset))->toBeFalse();
    expect(AssetResource::canCreate())->toBeFalse();
    $this->get(AssetResource::getUrl('edit', ['record' => $asset]))->assertForbidden();

    livewire(AccountBalancesRelationManager::class, [
        'ownerRecord' => $asset,
        'pageClass'   => ViewAsset::class,
    ])
        ->assertSuccessful()
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete')
        ->assertDontSee('Add Balance');

    expect($balance->fresh())->not->toBeNull();
});
