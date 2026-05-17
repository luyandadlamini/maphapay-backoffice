<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pricing\CustomerSegmentResource\Pages;

use App\Filament\Admin\Resources\Pricing\CustomerSegmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCustomerSegments extends ListRecords
{
    protected static string $resource = CustomerSegmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
