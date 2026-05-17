<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing\PricingScenarioResource\Pages;

use App\Filament\Admin\Resources\Pricing\PricingScenarioResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPricingScenarios extends ListRecords
{
    protected static string $resource = PricingScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
