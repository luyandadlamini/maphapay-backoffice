<?php

namespace App\Filament\Admin\Resources\CardIssuanceResource\Pages;

use App\Filament\Admin\Resources\CardIssuanceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCardIssuances extends ListRecords
{
    protected static string $resource = CardIssuanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
