<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Domain\Asset\Models\Asset;
use App\Filament\Admin\Resources\OrderResource;

it('uses tradeable assets when the tenant schema has the tradeable flag', function (): void {
    Asset::query()->updateOrCreate(['code' => 'AAA'], [
        'name'         => 'Tradeable Test Asset',
        'type'         => 'fiat',
        'precision'    => 2,
        'is_active'    => true,
        'is_basket'    => false,
        'is_tradeable' => true,
    ]);

    Asset::query()->updateOrCreate(['code' => 'ZZZ'], [
        'name'         => 'Untradeable Test Asset',
        'type'         => 'fiat',
        'precision'    => 0,
        'is_active'    => true,
        'is_basket'    => false,
        'is_tradeable' => false,
    ]);

    expect(OrderResource::baseCurrencyFilterOptions(hasTradeableColumn: true))
        ->toHaveKey('AAA')
        ->not->toHaveKey('ZZZ');
});

it('falls back to active asset codes when the tenant schema lacks the tradeable flag', function (): void {
    Asset::query()->updateOrCreate(['code' => 'BBB'], [
        'name'         => 'Fallback Test Asset',
        'type'         => 'fiat',
        'precision'    => 2,
        'is_active'    => true,
        'is_basket'    => false,
        'is_tradeable' => false,
    ]);

    expect(OrderResource::baseCurrencyFilterOptions(hasTradeableColumn: false))
        ->toHaveKey('BBB');
});
