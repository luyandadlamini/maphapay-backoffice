<?php

namespace App\Filament\Admin\Resources\SupportCaseResource\Pages;

use App\Filament\Admin\Resources\SupportCaseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupportCases extends ListRecords
{
    protected static string $resource = SupportCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
