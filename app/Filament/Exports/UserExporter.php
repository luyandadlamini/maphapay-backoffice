<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\User;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class UserExporter extends Exporter
{
    protected static ?string $model = User::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('uuid')
                ->label('User ID'),
            ExportColumn::make('name')
                ->label('Full Name'),
            ExportColumn::make('email')
                ->label('Email Address'),
            ExportColumn::make('email_verified_at')
                ->label('Email Verified'),
            ExportColumn::make('accounts_count')
                ->label('Number of Accounts')
                ->counts('accounts'),
            ExportColumn::make('created_at')
                ->label('Registration Date'),
            ExportColumn::make('updated_at')
                ->label('Last Updated'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your user export has completed and ' . number_format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}
