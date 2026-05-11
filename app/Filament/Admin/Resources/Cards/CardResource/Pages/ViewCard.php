<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\CardResource\Pages;

use App\Filament\Admin\Resources\Cards\CardResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCard extends ViewRecord
{
    protected static string $resource = CardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
