<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Account\Models\MinorAccountLifecycleException;
use App\Domain\Account\Services\MinorAccountLifecycleService;
use App\Filament\Admin\Resources\MinorAccountLifecycleExceptionResource\Pages;
use App\Filament\Admin\Resources\MinorAccountLifecycleExceptionResource\RelationManagers;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MinorAccountLifecycleExceptionResource extends Resource
{
    protected static ?string $model = MinorAccountLifecycleException::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Transactions';

    protected static ?string $modelLabel = 'Minor Account Lifecycle Exception';

    protected static ?string $pluralModelLabel = 'Minor Account Lifecycle Exceptions';

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
            TextInput::make('id')->disabled(),
            TextInput::make('minor_account_uuid')->disabled(),
            TextInput::make('transition_id')->disabled(),
            TextInput::make('reason_code')->disabled(),
            TextInput::make('status')->disabled(),
            TextInput::make('source')->disabled(),
            TextInput::make('occurrence_count')->disabled(),
            TextInput::make('sla_due_at')->disabled(),
            TextInput::make('sla_escalated_at')->disabled(),
            KeyValue::make('metadata')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('Exception')->copyable()->searchable()->limit(12),
                TextColumn::make('minor_account_uuid')->label('Minor Account')->copyable()->toggleable(),
                TextColumn::make('reason_code')->badge()->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('source')->badge()->sortable(),
                TextColumn::make('occurrence_count')->label('Occurrences')->sortable(),
                TextColumn::make('last_seen_at')->dateTime()->sortable(),
                TextColumn::make('sla_due_at')->label('SLA due')->dateTime()->sortable()->toggleable(),
                TextColumn::make('sla_escalated_at')->label('SLA flagged')->dateTime()->sortable()->toggleable(),
                TextColumn::make('sla_state')
                    ->label('SLA state')
                    ->badge()
                    ->getStateUsing(function (MinorAccountLifecycleException $record): string {
                        if ($record->status === MinorAccountLifecycleException::STATUS_RESOLVED) {
                            return 'resolved';
                        }
                        if ($record->sla_escalated_at !== null) {
                            return 'escalated';
                        }
                        if ($record->sla_due_at !== null && now()->isAfter($record->sla_due_at)) {
                            return 'breached';
                        }

                        return 'on_track';
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        MinorAccountLifecycleException::STATUS_OPEN => 'Open',
                        MinorAccountLifecycleException::STATUS_RESOLVED => 'Resolved',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Action::make('acknowledge_exception')
                    ->label('Acknowledge Exception')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('note')->required()->minLength(5)->maxLength(500),
                    ])
                    ->visible(fn (MinorAccountLifecycleException $record): bool => $record->status === MinorAccountLifecycleException::STATUS_OPEN)
                    ->action(function (MinorAccountLifecycleException $record, array $data): void {
                        $user = auth()->user();
                        if ($user === null) {
                            return;
                        }

                        app(MinorAccountLifecycleService::class)->acknowledgeException(
                            $record,
                            $user,
                            (string) $data['note'],
                        );
                    }),
                Action::make('resolve_exception')
                    ->label('Resolve Exception')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('note')->required()->minLength(5)->maxLength(500),
                    ])
                    ->visible(fn (MinorAccountLifecycleException $record): bool => $record->status === MinorAccountLifecycleException::STATUS_OPEN)
                    ->action(function (MinorAccountLifecycleException $record, array $data): void {
                        $user = auth()->user();
                        if ($user === null) {
                            return;
                        }

                        app(MinorAccountLifecycleService::class)->resolveException(
                            $record,
                            $user,
                            (string) $data['note'],
                            'filament_manual_resolve',
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
            'index' => Pages\ListMinorAccountLifecycleExceptions::route('/'),
            'view' => Pages\ViewMinorAccountLifecycleException::route('/{record}'),
        ];
    }
}
