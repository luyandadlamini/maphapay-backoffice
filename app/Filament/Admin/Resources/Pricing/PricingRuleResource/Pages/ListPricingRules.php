<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing\PricingRuleResource\Pages;

use App\Filament\Admin\Resources\Pricing\PricingRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPricingRules extends ListRecords
{
    protected static string $resource = PricingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
