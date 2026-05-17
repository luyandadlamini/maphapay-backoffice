<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing\PricingProductResource\Pages;

use App\Filament\Admin\Resources\Pricing\PricingProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPricingProducts extends ListRecords
{
    protected static string $resource = PricingProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
