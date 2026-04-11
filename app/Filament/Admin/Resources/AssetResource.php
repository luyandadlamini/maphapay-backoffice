<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\ExchangeRate;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Filament\Admin\Resources\AssetResource\Pages;
use App\Filament\Admin\Resources\AssetResource\RelationManagers;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class AssetResource extends Resource
{
    use HasBackofficeWorkspace;
    use \App\Filament\Admin\Traits\RespectsModuleVisibility;

    protected static ?string $model = Asset::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Finance & Reconciliation';

    protected static ?int $navigationSort = 1;

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
                    Forms\Components\Section::make('Asset Information')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('code')
                                    ->label('Asset Code')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(10)
                                    ->placeholder('USD, EUR, BTC, etc.')
                                    ->helperText('Unique identifier for the asset (e.g., USD, EUR, BTC)'),

                                Forms\Components\TextInput::make('name')
                                    ->label('Asset Name')
                                    ->required()
                                    ->maxLength(100)
                                    ->placeholder('US Dollar, Euro, Bitcoin, etc.')
                                    ->helperText('Full name of the asset'),

                                Forms\Components\Select::make('type')
                                    ->label('Asset Type')
                                    ->required()
                                    ->options(
                                        [
                                            'fiat'      => 'Fiat Currency',
                                            'crypto'    => 'Cryptocurrency',
                                            'commodity' => 'Commodity',
                                        ]
                                    )
                                    ->reactive()
                                    ->helperText('Type of asset being added'),

                                Forms\Components\TextInput::make('symbol')
                                    ->label('Symbol')
                                    ->maxLength(10)
                                    ->placeholder('$, €, ₿, etc.')
                                    ->helperText('Display symbol for the asset'),

                                Forms\Components\TextInput::make('precision')
                                    ->label('Decimal Precision')
                                    ->required()
                                    ->numeric()
                                    ->default(
                                        fn (Forms\Get $get) => match ($get('type')) {
                                            'fiat'      => 2,
                                            'crypto'    => 8,
                                            'commodity' => 4,
                                            default     => 2,
                                        }
                                    )
                                    ->minValue(0)
                                    ->maxValue(18)
                                    ->helperText('Number of decimal places for this asset'),
                            ]
                        ),

                    Forms\Components\Section::make('Status & Configuration')
                        ->schema(
                            [
                                Forms\Components\Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true)
                                    ->helperText('Whether this asset is available for transactions'),

                                Forms\Components\KeyValue::make('metadata')
                                    ->label('Metadata')
                                    ->keyLabel('Property')
                                    ->valueLabel('Value')
                                    ->helperText('Additional properties and configuration for this asset')
                                    ->columnSpanFull(),
                            ]
                        ),
                ]
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('code')
                        ->label('Code')
                        ->searchable()
                        ->sortable()
                        ->weight('bold')
                        ->badge()
                        ->color(
                            fn (string $state): string => match (true) {
                                in_array($state, ['USD', 'EUR', 'GBP']) => 'success',
                                in_array($state, ['BTC', 'ETH'])        => 'warning',
                                default                                 => 'primary',
                            }
                        ),

                    Tables\Columns\TextColumn::make('name')
                        ->label('Name')
                        ->searchable()
                        ->sortable(),

                    Tables\Columns\TextColumn::make('type')
                        ->label('Type')
                        ->sortable()
                        ->badge()
                        ->color(
                            fn (string $state): string => match ($state) {
                                'fiat'      => 'success',
                                'crypto'    => 'warning',
                                'commodity' => 'info',
                                default     => 'gray',
                            }
                        ),

                    Tables\Columns\TextColumn::make('symbol')
                        ->label('Symbol')
                        ->placeholder('—'),

                    Tables\Columns\TextColumn::make('precision')
                        ->label('Precision')
                        ->suffix(' decimals')
                        ->alignCenter(),

                    Tables\Columns\IconColumn::make('is_active')
                        ->label('Active')
                        ->boolean()
                        ->alignCenter(),

                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Created')
                        ->dateTime()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),

                    Tables\Columns\TextColumn::make('updated_at')
                        ->label('Updated')
                        ->dateTime()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ]
            )
            ->filters(
                [
                    Tables\Filters\SelectFilter::make('type')
                        ->options(
                            [
                                'fiat'      => 'Fiat Currency',
                                'crypto'    => 'Cryptocurrency',
                                'commodity' => 'Commodity',
                            ]
                        ),

                    Tables\Filters\TernaryFilter::make('is_active')
                        ->label('Active Status')
                        ->placeholder('All assets')
                        ->trueLabel('Active only')
                        ->falseLabel('Inactive only'),
                ]
            )
            ->actions(
                [
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\Action::make('requestEdit')
                        ->label('Edit Asset')
                        ->icon('heroicon-m-pencil-square')
                        ->fillForm(fn (Asset $record): array => static::assetFormData($record))
                        ->form(static::assetChangeRequestSchema())
                        ->action(function (Asset $record, array $data): void {
                            static::requestAssetEditApproval($record, $data);

                            Notification::make()
                                ->title('Asset update request submitted')
                                ->warning()
                                ->send();
                        }),
                    Tables\Actions\Action::make('delete')
                        ->label('Delete')
                        ->icon('heroicon-m-trash')
                        ->color('danger')
                        ->form(static::reasonSchema())
                        ->action(function (Asset $record, array $data): void {
                            static::requestAssetDeletionApproval(
                                record: $record,
                                reason: (string) $data['reason'],
                            );

                            Notification::make()
                                ->title('Asset deletion request submitted')
                                ->warning()
                                ->send();
                        })
                        ->requiresConfirmation(),
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
                                ->form(static::reasonSchema())
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
                                ->form(static::reasonSchema())
                                ->action(function (Collection $records, array $data): void {
                                    static::requestBulkStatusChangeApproval(
                                        records: $records,
                                        requestedState: 'inactive',
                                        reason: (string) $data['reason'],
                                    );
                                })
                                ->requiresConfirmation()
                                ->deselectRecordsAfterCompletion(),
                        ]
                    ),
                ]
            )
            ->defaultSort('code');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema(
                [
                    Infolists\Components\Section::make('Asset Information')
                        ->schema(
                            [
                                Infolists\Components\TextEntry::make('code')
                                    ->label('Asset Code')
                                    ->badge()
                                    ->color('primary'),

                                Infolists\Components\TextEntry::make('name')
                                    ->label('Asset Name'),

                                Infolists\Components\TextEntry::make('type')
                                    ->label('Asset Type')
                                    ->badge()
                                    ->color(
                                        fn (string $state): string => match ($state) {
                                            'fiat'      => 'success',
                                            'crypto'    => 'warning',
                                            'commodity' => 'info',
                                            default     => 'gray',
                                        }
                                    ),

                                Infolists\Components\TextEntry::make('symbol')
                                    ->label('Symbol')
                                    ->placeholder('—'),

                                Infolists\Components\TextEntry::make('precision')
                                    ->label('Decimal Precision')
                                    ->suffix(' decimals'),

                                Infolists\Components\IconEntry::make('is_active')
                                    ->label('Active Status')
                                    ->boolean(),
                            ]
                        )
                        ->columns(2),

                    Infolists\Components\Section::make('Metadata')
                        ->schema(
                            [
                                Infolists\Components\KeyValueEntry::make('metadata')
                                    ->label('Asset Properties')
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
            RelationManagers\AccountBalancesRelationManager::class,
            RelationManagers\ExchangeRatesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAssets::route('/'),
            'create' => Pages\CreateAsset::route('/create'),
            'view'   => Pages\ViewAsset::route('/{record}'),
            'edit'   => Pages\EditAsset::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function getNavigationBadgeColor(): string
    {
        return static::getModel()::count() > 10 ? 'warning' : 'primary';
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function assetChangeRequestSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label('Asset Name')
                ->required()
                ->maxLength(100),
            Forms\Components\Select::make('type')
                ->label('Asset Type')
                ->required()
                ->options([
                    'fiat' => 'Fiat Currency',
                    'crypto' => 'Cryptocurrency',
                    'commodity' => 'Commodity',
                    'custom' => 'Custom Asset',
                ]),
            Forms\Components\TextInput::make('symbol')
                ->label('Symbol')
                ->maxLength(10),
            Forms\Components\TextInput::make('precision')
                ->label('Decimal Precision')
                ->required()
                ->numeric()
                ->minValue(0)
                ->maxValue(18),
            Forms\Components\Toggle::make('is_active')
                ->label('Active'),
            Forms\Components\KeyValue::make('metadata')
                ->label('Metadata')
                ->keyLabel('Property')
                ->valueLabel('Value')
                ->columnSpanFull(),
            Forms\Components\Textarea::make('reason')
                ->label('Reason')
                ->required()
                ->minLength(10)
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function reasonSchema(): array
    {
        return [
            Forms\Components\Textarea::make('reason')
                ->label('Reason')
                ->required()
                ->minLength(10),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function assetFormData(Asset $record): array
    {
        return [
            'name' => $record->name,
            'type' => $record->type,
            'symbol' => $record->symbol,
            'precision' => $record->precision,
            'is_active' => $record->is_active,
            'metadata' => $record->metadata ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function requestAssetEditApproval(Asset $record, array $data): void
    {
        static::authorizeWorkspace();

        $requestedValues = [
            'name' => (string) $data['name'],
            'type' => (string) $data['type'],
            'symbol' => $data['symbol'] !== null ? (string) $data['symbol'] : null,
            'precision' => (int) $data['precision'],
            'is_active' => (bool) $data['is_active'],
            'metadata' => $data['metadata'] ?? [],
        ];

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.assets.edit',
            reason: (string) $data['reason'],
            targetType: Asset::class,
            targetIdentifier: (string) $record->getKey(),
            payload: [
                'asset_code' => $record->code,
                'old_values' => static::assetFormData($record),
                'requested_values' => $requestedValues,
            ],
        );
    }

    public static function requestAssetDeletionApproval(Asset $record, string $reason): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.assets.delete',
            reason: $reason,
            targetType: Asset::class,
            targetIdentifier: (string) $record->getKey(),
            payload: [
                'asset_code' => $record->code,
                'asset_name' => $record->name,
                'requested_state' => 'deleted',
            ],
        );
    }

    public static function requestAssetStatusApproval(Asset $record, string $requestedState, string $reason): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: sprintf('backoffice.assets.%s', $requestedState === 'active' ? 'activate' : 'deactivate'),
            reason: $reason,
            targetType: Asset::class,
            targetIdentifier: (string) $record->getKey(),
            payload: [
                'asset_code' => $record->code,
                'asset_name' => $record->name,
                'current_state' => $record->is_active ? 'active' : 'inactive',
                'requested_state' => $requestedState,
            ],
        );
    }

    /**
     * @param  Collection<int, Asset>  $records
     */
    public static function requestBulkStatusChangeApproval(Collection $records, string $requestedState, string $reason): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: sprintf('backoffice.assets.bulk_%s', $requestedState === 'active' ? 'activate' : 'deactivate'),
            reason: $reason,
            payload: [
                'requested_state' => $requestedState,
                'record_count' => $records->count(),
                'asset_codes' => $records
                    ->map(fn (Asset $record): string => (string) $record->getKey())
                    ->values()
                    ->all(),
            ],
        );
    }

    public static function requestExchangeRateDeletionApprovalFromAsset(ExchangeRate $record, string $reason): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.exchange_rates.delete',
            reason: $reason,
            targetType: ExchangeRate::class,
            targetIdentifier: (string) $record->getKey(),
            payload: [
                'pair' => ExchangeRateResource::exchangeRatePair($record),
                'source' => $record->source,
                'rate' => ExchangeRateResource::formatRateValue($record->rate),
                'requested_state' => 'deleted',
                'context' => 'asset_relation_manager',
            ],
        );
    }

    public static function requestExchangeRateStatusApprovalFromAsset(ExchangeRate $record, string $requestedState, string $reason): void
    {
        static::authorizeWorkspace();

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: sprintf('backoffice.exchange_rates.%s', $requestedState === 'active' ? 'activate' : 'deactivate'),
            reason: $reason,
            targetType: ExchangeRate::class,
            targetIdentifier: (string) $record->getKey(),
            payload: [
                'pair' => ExchangeRateResource::exchangeRatePair($record),
                'source' => $record->source,
                'current_state' => $record->is_active ? 'active' : 'inactive',
                'requested_state' => $requestedState,
                'context' => 'asset_relation_manager',
            ],
        );
    }

    /**
     * @param  Collection<int, ExchangeRate>  $records
     */
    public static function requestBulkExchangeRateStatusApprovalFromAsset(Collection $records, string $requestedState, string $reason): void
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
                    ->map(fn (ExchangeRate $record): string => ExchangeRateResource::exchangeRatePair($record))
                    ->values()
                    ->all(),
                'exchange_rate_ids' => $records
                    ->map(fn (ExchangeRate $record): string => (string) $record->getKey())
                    ->values()
                    ->all(),
                'context' => 'asset_relation_manager',
            ],
        );
    }

    public static function refreshExchangeRateFromAsset(ExchangeRate $record, string $reason): void
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
                'pair' => ExchangeRateResource::exchangeRatePair($record),
                'source' => $record->source,
                'context' => 'asset_relation_manager',
            ],
            tags: 'backoffice,finance,exchange-rates'
        );

        Notification::make()
            ->title('Exchange rate refreshed')
            ->success()
            ->send();
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
