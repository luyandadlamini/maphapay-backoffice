<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditLogResource\Pages;

use App\Filament\Admin\Resources\AuditLogResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportAuditTrail')
                ->label('Export Audit Trail')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Export Audit Trail')
                ->modalDescription('This will export all audit logs as a CSV with an appended SHA-256 hash for tamper-evidence.')
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->required()
                        ->minLength(10),
                ])
                ->action(fn (array $data): StreamedResponse => AuditLogResource::exportAll((string) $data['reason'])),
        ];
    }
}
