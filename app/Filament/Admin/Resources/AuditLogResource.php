<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Compliance\Models\AuditLog;
use App\Filament\Admin\Resources\AuditLogResource\Pages;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Compliance';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Audit Logs';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-audit-logs') ?? false;
    }

    public static function getHeaderActions(): array
    {
        return [
            Action::make('exportAuditTrail')
                ->label('Export Audit Trail')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Export Audit Trail')
                ->modalDescription('This will export all audit logs as a CSV with an appended SHA-256 hash for tamper-evidence.')
                ->action(fn (AuditLogResource $static) => $static->exportWithHash()),
        ];
    }

    private function exportWithHash(): void
    {
        $logs = AuditLog::query()
            ->orderBy('created_at', 'desc')
            ->get();

        $filename = 'audit-trail-' . now()->format('Y-m-d-His') . '.csv';

        $callback = function () use ($logs) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['ID', 'Action', 'Subject Type', 'User UUID', 'IP Address', 'Timestamp', 'Tamper Evidence Hash']);

            foreach ($logs as $log) {
                $row = [
                    $log->id,
                    $log->action ?? '',
                    $log->auditable_type ?? '',
                    $log->user_uuid ?? '',
                    $log->ip_address ?? '',
                    $log->created_at?->toIso8601String() ?? '',
                ];

                // SHA-256 Hash for regulatory compliance
                $row[] = hash('sha256', implode('|', $row));

                fputcsv($handle, $row);
            }

            fclose($handle);
        };

        $response = response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);

        Notification::make()
            ->title('Audit trail exported')
            ->body("File: {$filename}")
            ->success()
            ->send();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('user_uuid')
                    ->label('User UUID')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('action')
                    ->label('Action')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('auditable_type')
                    ->label('Subject Type')
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('auditable_id')
                    ->label('Subject ID')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP Address')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('action')
                    ->options([
                        'created'  => 'Created',
                        'updated'  => 'Updated',
                        'deleted'  => 'Deleted',
                        'viewed'   => 'Viewed',
                        'exported' => 'Exported',
                    ]),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('exportSelected')
                    ->label('Export Selected')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($records): StreamedResponse {
                        $filename = 'audit-export-' . now()->format('Y-m-d-His') . '.csv';

                        return response()->streamDownload(function () use ($records) {
                            $handle = fopen('php://output', 'w');
                            fputcsv($handle, ['ID', 'Action', 'Subject Type', 'User UUID', 'IP Address', 'Timestamp', 'Tamper Evidence Hash']);

                            foreach ($records as $log) {
                                $row = [
                                    $log->id,
                                    $log->action ?? '',
                                    $log->auditable_type ?? '',
                                    $log->user_uuid ?? '',
                                    $log->ip_address ?? '',
                                    $log->created_at?->toIso8601String() ?? '',
                                ];
                                $row[] = hash('sha256', implode('|', $row));
                                fputcsv($handle, $row);
                            }

                            fclose($handle);
                        }, $filename, ['Content-Type' => 'text/csv']);
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
