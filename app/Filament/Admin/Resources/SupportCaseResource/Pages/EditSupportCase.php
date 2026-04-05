<?php

namespace App\Filament\Admin\Resources\SupportCaseResource\Pages;

use App\Filament\Admin\Resources\SupportCaseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupportCase extends EditRecord
{
    protected static string $resource = SupportCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
