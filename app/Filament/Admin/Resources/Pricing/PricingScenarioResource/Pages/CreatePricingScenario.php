<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing\PricingScenarioResource\Pages;

use App\Filament\Admin\Resources\Pricing\PricingScenarioResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingScenario extends CreateRecord
{
    protected static string $resource = PricingScenarioResource::class;
}
