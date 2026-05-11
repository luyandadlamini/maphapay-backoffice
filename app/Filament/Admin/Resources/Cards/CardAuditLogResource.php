<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards;

use App\Domain\CardSubscriptions\Enums\CardActorType;
use App\Domain\CardSubscriptions\Models\CardAuditLog;
use App\Filament\Admin\Resources\Cards\CardAuditLogResource\Pages;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use BackedEnum;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardAuditLogResource extends Resource
{
    protected static ?string $model = CardAuditLog::class;

    protected static ?string $navigationGroup = 'Cards';

    protected static ?string $navigationLabel = 'Audit Logs';

    protected static ?int $navigationSort = 19;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('actor_type')
                    ->badge()
                    ->color(fn (CardActorType $state): string => match ($state) {
                        CardActorType::Admin     => 'danger',
                        CardActorType::User      => 'info',
                        CardActorType::System    => 'warning',
                        CardActorType::Processor => 'gray',
                    }),
                Tables\Columns\TextColumn::make('actor.name')->label('Actor')->placeholder('System')->searchable(),
                Tables\Columns\TextColumn::make('action')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('entity_type')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—'),
                Tables\Columns\TextColumn::make('entity_id')->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('ip_address')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('device_id')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('actor_type')
                    ->options(CardActorType::class)
                    ->multiple(),
                Tables\Filters\SelectFilter::make('entity_type')
                    ->options([
                        'App\\Domain\\CardIssuance\\Models\\Card'                   => 'Card',
                        'App\\Domain\\CardSubscriptions\\Models\\CardSubscription'  => 'Subscription',
                        'App\\Domain\\CardSubscriptions\\Models\\CardDispute'       => 'Dispute',
                        'App\\Domain\\CardSubscriptions\\Models\\CardRiskEvent'     => 'Risk Event',
                        'App\\Domain\\CardSubscriptions\\Models\\PhysicalCardOrder' => 'Physical Order',
                    ]),
                Tables\Filters\Filter::make('action')
                    ->form([Forms\Components\TextInput::make('action')->placeholder('e.g. card.admin_frozen')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['action'] ?? null,
                        fn ($q, $v) => $q->whereRaw('`action` LIKE ?', ["%{$v}%"]),
                    )),
                Tables\Filters\Filter::make('entity_id')
                    ->form([Forms\Components\TextInput::make('entity_id')->label('Entity ID')])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['entity_id'] ?? null,
                        fn ($q, $v) => $q->whereRaw('`entity_id` = ?', [$v]),
                    )),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from'),
                        Forms\Components\DatePicker::make('created_until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['created_from'], fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['created_until'], fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('exportSelected')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('export', new CardAuditLog()))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->minLength(10),
                    ])
                    ->action(function (Collection $records, array $data): StreamedResponse {
                        return static::exportLogs($records, (string) $data['reason']);
                    }),
            ]);
    }

    /**
     * @param Collection<int, CardAuditLog> $logs
     */
    public static function exportLogs(Collection $logs, string $reason): StreamedResponse
    {
        $first = $logs->first();
        Gate::authorize('export', $first instanceof CardAuditLog ? $first : new CardAuditLog());

        app(BackofficeWorkspaceAccess::class)->authorize('compliance');

        /** @var \App\Models\User|null $actor */
        $actor = auth()->user();

        $filename = 'card-audit-logs-' . now()->format('Y-m-d-His') . '.csv';

        app(AdminActionGovernance::class)->auditDirectAction(
            workspace: 'compliance',
            action: 'backoffice.card_audit_logs.exported',
            reason: $reason,
            metadata: [
                'export_scope' => 'selected',
                'record_count' => $logs->count(),
                'filename'     => $filename,
                'actor_email'  => $actor instanceof \App\Models\User ? $actor->email : 'system',
            ],
            tags: 'backoffice,cards,audit-logs',
        );

        return response()->streamDownload(function () use ($logs): void {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new RuntimeException('Unable to open output stream for CSV export.');
            }
            fputcsv($handle, ['id', 'created_at', 'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id', 'ip_address']);

            foreach ($logs as $log) {
                /** @var CardAuditLog $log */
                $actorType = $log->actor_type instanceof BackedEnum ? $log->actor_type->value : (string) $log->actor_type;
                fputcsv($handle, [
                    (string) $log->id,
                    $log->created_at->toIso8601String(),
                    $actorType,
                    (string) ($log->actor_id ?? ''),
                    (string) $log->action,
                    (string) ($log->entity_type ?? ''),
                    (string) ($log->entity_id ?? ''),
                    (string) ($log->ip_address ?? ''),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCardAuditLogs::route('/'),
            'view'  => Pages\ViewCardAuditLog::route('/{record}'),
        ];
    }
}
