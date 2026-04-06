<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Domain\Account\Models\Account;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class AccountExporter extends Exporter
{
    protected static ?string $model = Account::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('uuid')
                ->label('Account ID'),
            ExportColumn::make('name')
                ->label('Account Name'),
            ExportColumn::make('user_uuid')
                ->label('User ID'),
            ExportColumn::make('balance')
                ->label('Balance (USD)')
                ->formatStateUsing(fn ($state) => number_format($state / 100, 2)),
            ExportColumn::make('frozen')
                ->label('Status')
                ->formatStateUsing(fn ($state) => $state ? 'Frozen' : 'Active'),
            ExportColumn::make('created_at')
                ->label('Created Date'),
            ExportColumn::make('updated_at')
                ->label('Last Updated'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your account export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
