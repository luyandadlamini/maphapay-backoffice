<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\Admin\Resources\BasketAssetResource;
use App\Filament\Admin\Resources\BasketAssetResource\Pages\ListBasketAssets;
use Filament\Tables\Table;
use Livewire\Mechanisms\ComponentRegistry;

it('can evaluate the needs rebalancing column visibility without a row record', function (): void {
    $livewire = app(ComponentRegistry::class)->new(ListBasketAssets::class);
    $table = BasketAssetResource::table(Table::make($livewire));

    $column = collect($table->getColumns())
        ->firstOrFail(fn ($column): bool => $column->getName() === 'needs_rebalancing');

    expect($column->isVisible())->toBeTrue();
});
