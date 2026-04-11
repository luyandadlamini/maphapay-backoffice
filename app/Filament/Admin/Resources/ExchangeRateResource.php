<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Asset\Models\ExchangeRate;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Filament\Admin\Resources\ExchangeRateResource\Pages;
use App\Filament\Admin\Resources\ExchangeRateResource\Widgets;
use App\Filament\Admin\Traits\RespectsModuleVisibility;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ExchangeRateResource extends Resource
{
    use HasBackofficeWorkspace;
    use RespectsModuleVisibility;

    protected static ?string $model = ExchangeRate::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Finance & Reconciliation';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Exchange Rates';

    protected static string $backofficeWorkspace = 'finance';

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

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(
                [
                    Forms\Components\Section::make('Exchange Rate Information')
                        ->schema(
                            [
                                Forms\Components\Select::make('from_asset_code')
                                    ->label('From Asset')
                                    ->relationship('fromAsset', 'code')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->reactive(),

                                Forms\Components\Select::make('to_asset_code')
                                    ->label('To Asset')
                                    ->relationship('toAsset', 'code')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->different('from_asset_code')
                                    ->helperText('Must be different from the source asset'),

                                Forms\Components\TextInput::make('rate')
                                    ->label('Exchange Rate')
                                    ->numeric()
                                    ->step(0.0000000001)
                                    ->required()
                                    ->minValue(0.0000000001)
                                    ->helperText('Rate for converting from source to target asset'),
                            ]
                        )
                        ->columns(3),

                    Forms\Components\Section::make('Rate Configuration')
                        ->schema(
                            [
                                Forms\Components\Select::make('source')
                                    ->label('Rate Source')
                                    ->options(
                                        [
                                            ExchangeRate::SOURCE_MANUAL => 'Manual Entry',
                                            ExchangeRate::SOURCE_API    => 'API Feed',
                                            ExchangeRate::SOURCE_ORACLE => 'Oracle Service',
                                            ExchangeRate::SOURCE_MARKET => 'Market Data',
                                        ]
                                    )
                                    ->default(ExchangeRate::SOURCE_MANUAL)
                                    ->required()
                                    ->reactive()
                                    ->helperText('Source of the exchange rate data'),

                                Forms\Components\DateTimePicker::make('valid_at')
                                    ->label('Valid From')
                                    ->default(now())
                                    ->required()
                                    ->before('expires_at')
                                    ->helperText('When this rate becomes effective'),

                                Forms\Components\DateTimePicker::make('expires_at')
                                    ->label('Expires At')
                                    ->after('valid_at')
                                    ->helperText('Leave empty for rates that don\'t expire'),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true)
                                    ->helperText('Whether this rate is available for use'),
                            ]
                        )
                        ->columns(2),

                    Forms\Components\Section::make('Additional Information')
                        ->schema(
                            [
                                Forms\Components\KeyValue::make('metadata')
                                    ->label('Metadata')
                                    ->keyLabel('Property')
                                    ->valueLabel('Value')
                                    ->helperText('Additional properties and configuration')
                                    ->columnSpanFull(),
                            ]
                        )
                        ->collapsible(),
                ]
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('from_asset_code')
                        ->label('From')
                        ->badge()
                        ->color('primary')
                        ->searchable()
                        ->sortable(),

                    Tables\Columns\TextColumn::make('to_asset_code')
                        ->label('To')
                        ->badge()
                        ->color('success')
                        ->searchable()
                        ->sortable(),

                    Tables\Columns\TextColumn::make('rate')
                        ->label('Rate')
                        ->numeric(decimalPlaces: 10)
                        ->sortable()
                        ->alignEnd(),

                    Tables\Columns\TextColumn::make('inverse_rate')
                        ->label('Inverse Rate')
                        ->state(fn ($record) => number_format($record->getInverseRate(), 10))
                        ->color('gray')
                        ->alignEnd(),

                    Tables\Columns\TextColumn::make('source')
                        ->label('Source')
                        ->badge()
                        ->color(
                            fn (string $state): string => match ($state) {
                                ExchangeRate::SOURCE_MANUAL => 'gray',
                                ExchangeRate::SOURCE_API    => 'success',
                                ExchangeRate::SOURCE_ORACLE => 'warning',
                                ExchangeRate::SOURCE_MARKET => 'info',
                                default                     => 'gray',
                            }
                        ),

                    Tables\Columns\TextColumn::make('age')
                        ->label('Age')
                        ->state(fn ($record) => self::formatAge($record->getAgeInMinutes()))
                        ->color(
                            fn ($record) => match (true) {
                                $record->getAgeInMinutes() < 60   => 'success',
                                $record->getAgeInMinutes() < 1440 => 'warning',
                                default                           => 'danger',
                            }
                        )
                        ->badge()
                        ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('valid_at', $direction === 'asc' ? 'desc' : 'asc')),

                    Tables\Columns\IconColumn::make('is_valid')
                        ->label('Valid')
                        ->state(fn ($record) => $record->isValid())
                        ->boolean()
                        ->alignCenter(),

                    Tables\Columns\IconColumn::make('is_active')
                        ->label('Active')
                        ->boolean()
                        ->alignCenter(),

                    Tables\Columns\TextColumn::make('expires_at')
                        ->label('Expires')
                        ->dateTime()
                        ->placeholder('Never')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ]
            )
            ->filters(
                [
                    Tables\Filters\SelectFilter::make('from_asset_code')
                        ->label('From Asset')
                        ->relationship('fromAsset', 'code')
                        ->searchable()
                        ->preload(),

                    Tables\Filters\SelectFilter::make('to_asset_code')
                        ->label('To Asset')
                        ->relationship('toAsset', 'code')
                        ->searchable()
                        ->preload(),

                    Tables\Filters\SelectFilter::make('source')
                        ->options(
                            [
                                ExchangeRate::SOURCE_MANUAL => 'Manual',
                                ExchangeRate::SOURCE_API    => 'API',
                                ExchangeRate::SOURCE_ORACLE => 'Oracle',
                                ExchangeRate::SOURCE_MARKET => 'Market',
                            ]
                        ),

                    Tables\Filters\TernaryFilter::make('is_active')
                        ->label('Active Status'),

                    Tables\Filters\Filter::make('valid_now')
                        ->label('Valid Now')
                        ->query(fn (Builder $query): Builder => $query->valid()),

                    Tables\Filters\Filter::make('expired')
                        ->label('Expired')
                        ->query(fn (Builder $query): Builder => $query->where('expires_at', '<=', now())),

                    Tables\Filters\Filter::make('stale')
                        ->label('Stale (>24h)')
                        ->query(fn (Builder $query): Builder => $query->where('valid_at', '<=', now()->subDay())),
                ]
            )
            ->actions(
                [
                    Tables\Actions\ViewAction::make(),

                    Tables\Actions\Action::make('setRate')
                        ->label('Set New Rate')
                        ->icon('heroicon-o-currency-dollar')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\TextInput::make('rate')
                                ->label('New Rate')
                                ->numeric()
                                ->step(0.0000000001)
                                ->required(),
                            Forms\Components\Textarea::make('reason')
                                ->label('Reason')
                                ->required()
                                ->minLength(10),
                        ])
                        ->visible(fn (): bool => static::canViewAny())
                        ->action(function (ExchangeRate $record, array $data): void {
                            static::requestExchangeRateChangeApproval(
                                record: $record,
                                requestedRate: (string) $data['rate'],
                                reason: (string) $data['reason'],
                            );

                            Notification::make()
                                ->title('Exchange rate update request submitted')
                                ->warning()
                                ->send();
                        }),

                    Tables\Actions\Action::make('delete')
                        ->label('Delete')
                        ->icon('heroicon-m-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Reason')
                                ->required()
                                ->minLength(10),
                        ])
                        ->action(function (ExchangeRate $record, array $data): void {
                            static::requestExchangeRateDeletionApproval(
                                record: $record,
                                reason: (string) $data['reason'],
                            );

                            Notification::make()
                                ->title('Exchange rate deletion request submitted')
                                ->warning()
                                ->send();
                        }),

                    Tables\Actions\Action::make('refresh')
                        ->label('Refresh')
                        ->icon('heroicon-m-arrow-path')
                        ->color('warning')
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Reason')
                                ->required()
                                ->minLength(10),
                        ])
                        ->action(function (ExchangeRate $record, array $data): void {
                            static::refreshExchangeRate(
                                record: $record,
                                reason: (string) $data['reason'],
                            );
                        })
                        ->requiresConfirmation()
                        ->visible(fn ($record) => $record->source !== ExchangeRate::SOURCE_MANUAL),
                ]
            )
            ->bulkActions(
                [
                    Tables\Actions\BulkActionGroup::make(
                        [
                            Tables\Actions\BulkAction::make('activate')
                                ->label('Activate')
                                ->icon('heroicon-m-check-circle')
                                ->color('success')
                                ->form([
                                    Forms\Components\Textarea::make('reason')
                                        ->label('Reason')
                                        ->required()
                                        ->minLength(10),
                                ])
                                ->action(function (Collection $records, array $data): void {
                                    static::requestBulkStatusChangeApproval(
                                        records: $records,
                                        requestedState: 'active',
                                        reason: (string) $data['reason'],
                                    );
                                })
                                ->deselectRecordsAfterCompletion(),

                            Tables\Actions\BulkAction::make('deactivate')
                                ->label('Deactivate')
                                ->icon('heroicon-m-x-circle')
                                ->color('danger')
                                ->form([
                                    Forms\Components\Textarea::make('reason')
                                        ->label('Reason')
                                        ->required()
                                        ->minLength(10),
                                ])
                                ->action(function (Collection $records, array $data): void {
                                    static::requestBulkStatusChangeApproval(
                                        records: $records,
                                        requestedState: 'inactive',
                                        reason: (string) $data['reason'],
                                    );
                                })
                                ->requiresConfirmation()
                                ->deselectRecordsAfterCompletion(),

                            Tables\Actions\BulkAction::make('refresh_rates')
                                ->label('Refresh Rates')
                                ->icon('heroicon-m-arrow-path')
                                ->color('warning')
                                ->form([
                                    Forms\Components\Textarea::make('reason')
                                        ->label('Reason')
                                        ->required()
                                        ->minLength(10),
                                ])
                                ->action(function (Collection $records, array $data): void {
                                    static::refreshExchangeRates(
                                        records: $records,
                                        reason: (string) $data['reason'],
                                    );
                                })
                                ->requiresConfirmation()
                                ->deselectRecordsAfterCompletion(),
                        ]
                    ),
                ]
            )
            ->defaultSort('valid_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema(
                [
                    Infolists\Components\Section::make('Exchange Rate Details')
                        ->schema(
                            [
                                Infolists\Components\TextEntry::make('pair')
                                    ->label('Asset Pair')
                                    ->state(fn ($record) => "{$record->from_asset_code} → {$record->to_asset_code}")
                                    ->badge()
                                    ->color('primary'),

                                Infolists\Components\TextEntry::make('rate')
                                    ->label('Exchange Rate')
                                    ->numeric(decimalPlaces: 10),

                                Infolists\Components\TextEntry::make('inverse_rate')
                                    ->label('Inverse Rate')
                                    ->state(fn ($record) => number_format($record->getInverseRate(), 10)),

                                Infolists\Components\TextEntry::make('source')
                                    ->label('Source')
                                    ->badge()
                                    ->color(
                                        fn (string $state): string => match ($state) {
                                            ExchangeRate::SOURCE_MANUAL => 'gray',
                                            ExchangeRate::SOURCE_API    => 'success',
                                            ExchangeRate::SOURCE_ORACLE => 'warning',
                                            ExchangeRate::SOURCE_MARKET => 'info',
                                            default                     => 'gray',
                                        }
                                    ),
                            ]
                        )
                        ->columns(2),

                    Infolists\Components\Section::make('Validity Information')
                        ->schema(
                            [
                                Infolists\Components\TextEntry::make('valid_at')
                                    ->label('Valid From')
                                    ->dateTime(),

                                Infolists\Components\TextEntry::make('expires_at')
                                    ->label('Expires At')
                                    ->dateTime()
                                    ->placeholder('Never expires'),

                                Infolists\Components\IconEntry::make('is_active')
                                    ->label('Active')
                                    ->boolean(),

                                Infolists\Components\TextEntry::make('age')
                                    ->label('Age')
                                    ->state(fn ($record) => self::formatAge($record->getAgeInMinutes()))
                                    ->badge()
                                    ->color(
                                        fn ($record) => match (true) {
                                            $record->getAgeInMinutes() < 60   => 'success',
                                            $record->getAgeInMinutes() < 1440 => 'warning',
                                            default                           => 'danger',
                                        }
                                    ),
                            ]
                        )
                        ->columns(2),

                    Infolists\Components\Section::make('Metadata')
                        ->schema(
                            [
                                Infolists\Components\KeyValueEntry::make('metadata')
                                    ->label('Additional Properties')
                                    ->columnSpanFull(),
                            ]
                        )
                        ->collapsible(),

                    Infolists\Components\Section::make('System Information')
                        ->schema(
                            [
                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Created At')
                                    ->dateTime(),

                                Infolists\Components\TextEntry::make('updated_at')
                                    ->label('Updated At')
                                    ->dateTime(),
                            ]
                        )
                        ->columns(2)
                        ->collapsible(),
                ]
            );
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListExchangeRates::route('/'),
            'create' => Pages\CreateExchangeRate::route('/create'),
            'view'   => Pages\ViewExchangeRate::route('/{record}'),
            'edit'   => Pages\EditExchangeRate::route('/{record}/edit'),
        ];
    }

    public static function getWidgets(): array
    {
        return [
            Widgets\ExchangeRateFreshnessWidget::class,
            Widgets\ExchangeRateStatsWidget::class,
            Widgets\ExchangeRateChartWidget::class,
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) (static::getModel()::valid()->count() . '/' . static::getModel()::count());
    }

    public static function getNavigationBadgeColor(): string
    {
        $total = static::getModel()::count();
        $valid = static::getModel()::valid()->count();

        if ($total === 0) {
            return 'gray';
        }

        $percentage = ($valid / $total) * 100;

        return match (true) {
            $percentage >= 80 => 'success',
            $percentage >= 60 => 'warning',
            default           => 'danger',
        };
    }

    protected static function formatAge(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}m";
        } elseif ($minutes < 1440) {
            $hours = intval($minutes / 60);

            return "{$hours}h";
        } else {
            $days = intval($minutes / 1440);

            return "{$days}d";
        }
    }

    public static function requestExchangeRateChangeApproval(ExchangeRate $record, string $requestedRate, string $reason): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.exchange_rates.set_rate',
            reason: $reason,
            targetType: ExchangeRate::class,
            targetIdentifier: (string) $record->getKey(),
            payload: [
                'pair' => static::exchangeRatePair($record),
                'source' => $record->source,
                'old_rate' => static::formatRateValue($record->rate),
                'requested_rate' => static::formatRateValue($requestedRate),
            ],
            metadata: [
                'from_asset_code' => $record->from_asset_code,
                'to_asset_code' => $record->to_asset_code,
            ],
        );
    }

    public static function requestExchangeRateDeletionApproval(ExchangeRate $record, string $reason): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.exchange_rates.delete',
            reason: $reason,
            targetType: ExchangeRate::class,
            targetIdentifier: (string) $record->getKey(),
            payload: [
                'pair' => static::exchangeRatePair($record),
                'source' => $record->source,
                'rate' => static::formatRateValue($record->rate),
                'requested_state' => 'deleted',
            ],
        );
    }

    public static function requestExchangeRateStatusApproval(ExchangeRate $record, string $requestedState, string $reason): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: sprintf('backoffice.exchange_rates.%s', $requestedState === 'active' ? 'activate' : 'deactivate'),
            reason: $reason,
            targetType: ExchangeRate::class,
            targetIdentifier: (string) $record->getKey(),
            payload: [
                'pair' => static::exchangeRatePair($record),
                'source' => $record->source,
                'current_state' => $record->is_active ? 'active' : 'inactive',
                'requested_state' => $requestedState,
            ],
        );
    }

    /**
     * @param  Collection<int, ExchangeRate>  $records
     */
    public static function requestBulkStatusChangeApproval(Collection $records, string $requestedState, string $reason): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: sprintf('backoffice.exchange_rates.bulk_%s', $requestedState === 'active' ? 'activate' : 'deactivate'),
            reason: $reason,
            payload: [
                'requested_state' => $requestedState,
                'record_count' => $records->count(),
                'pairs' => $records
                    ->map(fn (Model $record): string => static::exchangeRatePair($record))
                    ->values()
                    ->all(),
                'exchange_rate_ids' => $records
                    ->map(fn (Model $record): string => (string) $record->getKey())
                    ->values()
                    ->all(),
            ],
        );
    }

    public static function refreshExchangeRate(ExchangeRate $record, string $reason): void
    {
        static::authorizeWorkspace();

        $previousValidAt = $record->valid_at->toIso8601String();
        $record->update(['valid_at' => now()]);
        $record->refresh();

        static::adminActionGovernance()->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.exchange_rates.refreshed',
            reason: $reason,
            auditable: $record,
            oldValues: ['valid_at' => $previousValidAt],
            newValues: ['valid_at' => $record->valid_at->toIso8601String()],
            metadata: [
                'pair' => static::exchangeRatePair($record),
                'source' => $record->source,
            ],
            tags: 'backoffice,finance,exchange-rates'
        );

        Notification::make()
            ->title('Exchange rate refreshed')
            ->success()
            ->send();
    }

    /**
     * @param  Collection<int, ExchangeRate>  $records
     */
    public static function refreshExchangeRates(Collection $records, string $reason): void
    {
        static::authorizeWorkspace();

        $refreshedAt = now();
        $records->each(function (Model $record) use ($refreshedAt): void {
            $record->update(['valid_at' => $refreshedAt]);
        });

        static::adminActionGovernance()->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.exchange_rates.bulk_refreshed',
            reason: $reason,
            metadata: [
                'record_count' => $records->count(),
                'pairs' => $records
                    ->map(fn (Model $record): string => static::exchangeRatePair($record))
                    ->values()
                    ->all(),
                'refreshed_at' => $refreshedAt->toIso8601String(),
            ],
            tags: 'backoffice,finance,exchange-rates'
        );

        Notification::make()
            ->title('Exchange rates refreshed')
            ->success()
            ->send();
    }

    public static function exchangeRatePair(ExchangeRate $record): string
    {
        return sprintf('%s/%s', $record->from_asset_code, $record->to_asset_code);
    }

    public static function formatRateValue(mixed $rate): string
    {
        return number_format((float) $rate, 10, '.', '');
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
