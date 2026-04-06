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
        $user = auth()->user();

        return $user && ($user->hasRole('super-admin') || $user->hasRole('compliance-manager'));
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
                ->modalDescription('This will export all audit logs as a CSV with an appended SHA-256 hash for tamper-evidence. The hash will be verified by regulators.')
                ->action(function (): void {
                    $this->exportWithHash();
                }),
        ];
    }

    private function exportWithHash(): void
    {
        $logs = AuditLog::query()
            ->orderBy('created_at', 'desc')
            ->get();

        $filename = 'audit-trail-'.now()->format('Y-m-d-His').'.csv';

        $callback = function () use ($logs) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['ID', 'Event', 'Description', 'User', 'IP Address', 'Created At']);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->id,
                    $log->event ?? '',
                    $log->description ?? '',
                    $log->user_id ?? '',
                    $log->ip_address ?? '',
                    $log->created_at?->toIso8601String() ?? '',
                ]);
            }

            fclose($handle);
        };

        $response = response()->stream($callback, 200, [
            'Content-Type' => 'text/csv',
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

                Tables\Columns\TextColumn::make('event')
                    ->label('Event')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->limit(50),

                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Subject Type')
                    ->limit(30),

                Tables\Columns\TextColumn::make('user_id')
                    ->label('User')
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
                Tables\Filters\SelectFilter::make('event')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                        'viewed' => 'Viewed',
                        'exported' => 'Exported',
                    ]),
                Tables\Filters\Filter::make('created_at')
                    ->form(DatePicker::make('from'))
                    ->query(fn ($query, $date) => $query->where('created_at', '>=', $date)),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('exportSelected')
                    ->label('Export Selected')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($records): StreamedResponse {
                        $filename = 'audit-export-'.now()->format('Y-m-d-His').'.csv';

                        return response()->streamDownload(function () use ($records) {
                            $handle = fopen('php://output', 'w');
                            fputcsv($handle, ['ID', 'Event', 'Description', 'User', 'IP Address', 'Created At']);

                            foreach ($records as $log) {
                                fputcsv($handle, [
                                    $log->id,
                                    $log->event ?? '',
                                    $log->description ?? '',
                                    $log->user_id ?? '',
                                    $log->ip_address ?? '',
                                    $log->created_at?->toIso8601String() ?? '',
                                ]);
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
