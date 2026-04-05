<?php

namespace App\Filament\Admin\Resources\SupportCaseResource\Pages;

use App\Filament\Admin\Resources\SupportCaseResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSupportCase extends ViewRecord
{
    protected static string $resource = SupportCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
