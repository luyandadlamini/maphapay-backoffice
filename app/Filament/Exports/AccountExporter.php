<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Domain\Account\Models\AccountMembership;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class AccountExporter extends Exporter
{
    protected static ?string $model = AccountMembership::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('account_uuid')
                ->label('Account ID'),
            ExportColumn::make('user_uuid')
                ->label('User ID'),
            ExportColumn::make('tenant_id')
                ->label('Tenant'),
            ExportColumn::make('account_type')
                ->label('Account Type'),
            ExportColumn::make('status')
                ->label('Status'),
            ExportColumn::make('created_at')
                ->label('Created Date'),
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
