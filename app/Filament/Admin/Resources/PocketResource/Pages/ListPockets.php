<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PocketResource\Pages;

use App\Filament\Admin\Resources\PocketResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPockets extends ListRecords
{
    protected static string $resource = PocketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}