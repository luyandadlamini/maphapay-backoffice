<?php

namespace App\Filament\Admin\Resources\CardIssuanceResource\Pages;

use App\Filament\Admin\Resources\CardIssuanceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCardIssuance extends EditRecord
{
    protected static string $resource = CardIssuanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
