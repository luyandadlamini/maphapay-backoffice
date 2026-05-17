<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing\PricingProductResource\Pages;

use App\Filament\Admin\Resources\Pricing\PricingProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePricingProduct extends CreateRecord
{
    protected static string $resource = PricingProductResource::class;
}
