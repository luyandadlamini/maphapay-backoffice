<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\CardResource\Pages;

use App\Filament\Admin\Resources\Cards\CardResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCards extends ListRecords
{
    protected static string $resource = CardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
