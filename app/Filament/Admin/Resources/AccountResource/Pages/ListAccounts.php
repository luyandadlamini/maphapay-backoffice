<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\Pages;

use App\Filament\Admin\Resources\AccountResource;
use App\Filament\Exports\AccountExporter;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\ExportAction::make()
                ->exporter(AccountExporter::class)
                ->label('Export Accounts')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success'),
        ];
    }
}
