<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Account\Models\MinorFamilyReconciliationException;
use App\Domain\Account\Models\MinorFamilyReconciliationExceptionAcknowledgment;
use App\Domain\Account\Services\MinorFamilyReconciliationExceptionQueueService;
use App\Filament\Admin\Resources\MinorFamilyReconciliationExceptionResource\Pages;
use App\Filament\Admin\Resources\MinorFamilyReconciliationExceptionResource\RelationManagers;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class MinorFamilyReconciliationExceptionResource extends Resource
{
    protected static ?string $model = MinorFamilyReconciliationException::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?string $modelLabel = 'Minor Family Reconciliation Exception';

    protected static ?string $pluralModelLabel = 'Minor Family Reconciliation Exceptions';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-transactions') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('id')->label('Exception UUID')->disabled(),
            TextInput::make('mtn_momo_transaction_id')->label('Transaction ID')->disabled(),
            TextInput::make('reason_code')->disabled(),
            TextInput::make('status')->disabled(),
            TextInput::make('source')->disabled(),
            TextInput::make('occurrence_count')->numeric()->disabled(),
            TextInput::make('first_seen_at')->disabled(),
            TextInput::make('last_seen_at')->disabled(),
            KeyValue::make('metadata')
                ->label('Artifact Metadata')
                ->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Exception')
                    ->copyable()
                    ->searchable()
                    ->limit(12),
                TextColumn::make('reason_code')
                    ->badge()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('source')
                    ->badge()
                    ->sortable(),
                TextColumn::make('occurrence_count')
                    ->label('Occurrences')
                    ->sortable(),
                TextColumn::make('mtn_momo_transaction_id')
                    ->label('MTN Transaction')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('sla_due_at')
                    ->label('SLA due')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sla_escalated_at')
                    ->label('SLA flagged')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sla_state')
                    ->label('SLA state')
                    ->badge()
                    ->getStateUsing(function (MinorFamilyReconciliationException $record): string {
                        if ($record->status === MinorFamilyReconciliationException::STATUS_RESOLVED) {
                            return 'resolved';
                        }
                        if ($record->sla_escalated_at !== null) {
                            return 'escalated';
                        }
                        if ($record->sla_due_at !== null && now()->isAfter($record->sla_due_at)) {
                            return 'breached';
                        }

                        return 'on_track';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'escalated', 'breached' => 'danger',
                        'on_track' => 'success',
                        'resolved' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        MinorFamilyReconciliationException::STATUS_OPEN => 'Open',
                        MinorFamilyReconciliationException::STATUS_RESOLVED => 'Resolved',
                    ]),
                Tables\Filters\SelectFilter::make('reason_code')
                    ->options([
                        'unresolved_outcome' => 'Unresolved Outcome',
                        'missing_tenant_context' => 'Missing Tenant Context',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Action::make('acknowledge_manual_review')
                    ->label('Acknowledge / Manual Review')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('note')
                            ->label('Review Note')
                            ->required()
                            ->minLength(5)
                            ->maxLength(500),
                    ])
                    ->visible(fn (MinorFamilyReconciliationException $record): bool => $record->status === MinorFamilyReconciliationException::STATUS_OPEN)
                    ->action(function (MinorFamilyReconciliationException $record, array $data): void {
                        $user = auth()->user();
                        if ($user === null || ! is_string($user->uuid) || $user->uuid === '') {
                            return;
                        }

                        $note = (string) ($data['note'] ?? '');
                        self::appendManualReviewMetadata($record, $user->uuid, $note);
                    }),
                Action::make('resolve_exception')
                    ->label('Resolve Exception')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('note')
                            ->label('Resolution Note')
                            ->required()
                            ->minLength(5)
                            ->maxLength(500),
                    ])
                    ->visible(fn (MinorFamilyReconciliationException $record): bool => $record->status === MinorFamilyReconciliationException::STATUS_OPEN)
                    ->action(function (MinorFamilyReconciliationException $record, array $data): void {
                        $user = auth()->user();
                        if ($user === null || ! is_string($user->uuid) || $user->uuid === '') {
                            return;
                        }

                        $note = (string) ($data['note'] ?? '');
                        self::appendManualReviewMetadata($record, $user->uuid, $note);

                        app(MinorFamilyReconciliationExceptionQueueService::class)->resolveException(
                            exception: $record->refresh(),
                            source: 'filament_manual_resolve',
                            metadata: [
                                'resolved_by_user_uuid' => $user->uuid,
                                'note' => $note,
                            ],
                        );
                    }),
                Action::make('reopen_exception')
                    ->label('Reopen Exception')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('note')
                            ->label('Reopen Reason')
                            ->required()
                            ->minLength(5)
                            ->maxLength(500),
                    ])
                    ->visible(fn (MinorFamilyReconciliationException $record): bool => $record->status === MinorFamilyReconciliationException::STATUS_RESOLVED)
                    ->action(function (MinorFamilyReconciliationException $record, array $data): void {
                        $user = auth()->user();
                        if ($user === null || ! is_string($user->uuid) || $user->uuid === '') {
                            return;
                        }

                        $note = (string) ($data['note'] ?? '');
                        self::appendManualReviewMetadata($record, $user->uuid, $note);

                        app(MinorFamilyReconciliationExceptionQueueService::class)->reopenException(
                            exception: $record->refresh(),
                            source: 'filament_manual_reopen',
                            metadata: [
                                'reopened_by_user_uuid' => $user->uuid,
                                'note' => $note,
                            ],
                        );
                    }),
            ])
            ->bulkActions([])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AcknowledgmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMinorFamilyReconciliationExceptions::route('/'),
            'view' => Pages\ViewMinorFamilyReconciliationException::route('/{record}'),
        ];
    }

    private static function appendManualReviewMetadata(
        MinorFamilyReconciliationException $record,
        string $userUuid,
        string $note,
    ): void {
        DB::transaction(function () use ($record, $userUuid, $note): void {
            /** @var MinorFamilyReconciliationException $lockedRecord */
            $lockedRecord = MinorFamilyReconciliationException::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var MinorFamilyReconciliationExceptionAcknowledgment $ack */
            $ack = MinorFamilyReconciliationExceptionAcknowledgment::query()->create([
                'minor_family_reconciliation_exception_id' => $lockedRecord->id,
                'acknowledged_by_user_uuid' => $userUuid,
                'note' => $note,
            ]);

            /** @var array<string, mixed> $metadata */
            $metadata = is_array($lockedRecord->metadata) ? $lockedRecord->metadata : [];
            $metadata['manual_review'] = [
                'acknowledged_by_user_uuid' => $userUuid,
                'acknowledged_at' => now()->toIso8601String(),
                'note' => $note,
                'latest_acknowledgment_id' => $ack->id,
            ];

            $lockedRecord->forceFill([
                'metadata' => $metadata,
            ])->save();
        });
    }
}
