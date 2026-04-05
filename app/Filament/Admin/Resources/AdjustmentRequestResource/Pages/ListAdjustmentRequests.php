<?php

namespace App\Filament\Admin\Resources\AdjustmentRequestResource\Pages;

use App\Filament\Admin\Resources\AdjustmentRequestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAdjustmentRequests extends ListRecords
{
    protected static string $resource = AdjustmentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
