<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Compliance\Models\AuditLog;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Filament\Admin\Resources\AuditLogResource\Pages;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogResource extends Resource
{
    use HasBackofficeWorkspace;

    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Audit Logs';

    protected static string $backofficeWorkspace = 'platform_administration';

    public static function canViewAny(): bool
    {
        return app(BackofficeWorkspaceAccess::class)->canAccess(static::getBackofficeWorkspace());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
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
                    ->form([
                        \Filament\Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(fn ($records, array $data): StreamedResponse => static::exportLogs(
                        logs: $records,
                        reason: (string) $data['reason'],
                        scope: 'selected',
                    )),
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

    public static function exportAll(string $reason): StreamedResponse
    {
        $logs = AuditLog::query()
            ->orderBy('created_at', 'desc')
            ->get();

        return static::exportLogs($logs, $reason, 'all');
    }

    /**
     * @param Collection<int, AuditLog> $logs
     */
    public static function exportLogs(Collection $logs, string $reason, string $scope): StreamedResponse
    {
        static::authorizeWorkspace();
        /** @var \App\Models\User|null $actor */
        $actor = auth()->user();

        $filename = 'audit-trail-' . now()->format('Y-m-d-His') . '.csv';

        static::adminActionGovernance()->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.audit_logs.exported',
            reason: $reason,
            metadata: [
                'export_scope' => $scope,
                'record_count' => $logs->count(),
                'filename' => $filename,
                'actor_email' => $actor instanceof \App\Models\User ? $actor->email : 'system',
            ],
            tags: 'backoffice,platform,audit-logs'
        );

        return response()->streamDownload(function () use ($logs): void {
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
                $row[] = hash('sha256', implode('|', $row));

                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public static function adminActionGovernance(): AdminActionGovernance
    {
        return app(AdminActionGovernance::class);
    }

    public static function authorizeWorkspace(): void
    {
        app(BackofficeWorkspaceAccess::class)->authorize(static::getBackofficeWorkspace());
    }
}
