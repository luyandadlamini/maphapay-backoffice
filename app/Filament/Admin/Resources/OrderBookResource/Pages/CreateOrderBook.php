<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\OrderBookResource\Pages;

use App\Filament\Admin\Resources\OrderBookResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrderBook extends CreateRecord
{
    protected static string $resource = OrderBookResource::class;
}
