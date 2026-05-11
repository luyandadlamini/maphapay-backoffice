<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\PhysicalCardOrderResource\Pages;

use App\Filament\Admin\Resources\Cards\PhysicalCardOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListPhysicalCardOrders extends ListRecords
{
    protected static string $resource = PhysicalCardOrderResource::class;
}
