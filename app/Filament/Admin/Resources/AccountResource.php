<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Services\AccountService;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Filament\Admin\Resources\AccountResource\Pages;
use App\Filament\Admin\Resources\AccountResource\RelationManagers;
use App\Filament\Admin\Traits\RespectsModuleVisibility;
use App\Support\Backoffice\AdminActionGovernance;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use App\Support\BankingDisplay;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AccountResource extends Resource
{
    use HasBackofficeWorkspace;
    use RespectsModuleVisibility;

    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Bank Accounts';

    protected static ?string $modelLabel = 'Bank Account';

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
        return false;
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
                    Section::make('Account Information')
                        ->description('Basic account details')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('uuid')
                                    ->label('Account UUID')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->visibleOn('edit'),
                                Forms\Components\TextInput::make('name')
                                    ->label('Account Name')
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder('e.g., John Doe Savings'),
                                Forms\Components\TextInput::make('user_uuid')
                                    ->label('User UUID')
                                    ->required()
                                    ->placeholder('UUID of the account owner')
                                    ->helperText('The unique identifier of the user who owns this account'),
                            ]
                        )->columns(2),

                    Section::make('Financial Details')
                        ->description('Account balance and status')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('balance')
                                    ->label('Current Balance')
                                    ->required()
                                    ->numeric()
                                    ->default(0)
                                    ->prefix(BankingDisplay::currencySymbolForForms())
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->helperText('Balance can only be modified through transactions'),
                                Forms\Components\Toggle::make('frozen')
                                    ->label('Account Frozen')
                                    ->helperText('Frozen accounts cannot perform transactions')
                                    ->reactive()
                                    ->afterStateUpdated(
                                        function ($state, $old): void {
                                            if ($state !== $old && $old !== null) {
                                                Notification::make()
                                                    ->title($state ? 'Account will be frozen' : 'Account will be unfrozen')
                                                    ->warning()
                                                    ->send();
                                            }
                                        }
                                    ),
                            ]
                        )->columns(2),
                ]
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('uuid')
                        ->label('Account ID')
                        ->copyable()
                        ->copyMessage('Account ID copied')
                        ->copyMessageDuration(1500)
                        ->searchable(),
                    Tables\Columns\TextColumn::make('name')
                        ->label('Account Name')
                        ->searchable()
                        ->sortable()
                        ->weight('bold'),
                    Tables\Columns\TextColumn::make('user_uuid')
                        ->label('User ID')
                        ->copyable()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('balance')
                        ->label('Balance')
                        ->money(config('banking.default_currency', 'SZL'), 100)
                        ->sortable()
                        ->color(fn ($state): string => $state < 0 ? 'danger' : 'success')
                        ->weight('bold'),
                    Tables\Columns\IconColumn::make('frozen')
                        ->label('Status')
                        ->boolean()
                        ->trueIcon('heroicon-o-lock-closed')
                        ->falseIcon('heroicon-o-lock-open')
                        ->trueColor('danger')
                        ->falseColor('success'),
                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Created')
                        ->dateTime('M j, Y g:i A')
                        ->sortable()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('updated_at')
                        ->label('Last Updated')
                        ->dateTime('M j, Y g:i A')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ]
            )
            ->defaultSort('created_at', 'desc')
            ->filters(
                [
                    SelectFilter::make('frozen')
                        ->label('Account Status')
                        ->options(
                            [
                                '0' => 'Active',
                                '1' => 'Frozen',
                            ]
                        ),
                    Filter::make('balance')
                        ->form(
                            [
                                Forms\Components\Select::make('balance_operator')
                                    ->label('Balance')
                                    ->options(
                                        [
                                            '>' => 'Greater than',
                                            '<' => 'Less than',
                                            '=' => 'Equal to',
                                        ]
                                    )
                                    ->default('>')
                                    ->required(),
                                Forms\Components\TextInput::make('balance_amount')
                                    ->label('Amount')
                                    ->numeric()
                                    ->default(0)
                                    ->prefix(BankingDisplay::currencySymbolForForms())
                                    ->required(),
                            ]
                        )
                        ->query(
                            function (Builder $query, array $data): Builder {
                                return $query
                                    ->when(
                                        $data['balance_amount'] ?? null,
                                        fn (Builder $query, $amount): Builder => $query->where(
                                            'balance',
                                            $data['balance_operator'] ?? '>',
                                            $amount * 100
                                        ),
                                    );
                            }
                        )
                        ->indicateUsing(
                            function (array $data): ?string {
                                if (! $data['balance_amount']) {
                                    return null;
                                }

                                return 'Balance ' . $data['balance_operator'] . ' ' . BankingDisplay::majorUnitsAsString((float) $data['balance_amount']);
                            }
                        ),
                ]
            )
            ->actions(
                [
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\Action::make('deposit')
                        ->label('Deposit')
                        ->icon('heroicon-o-plus-circle')
                        ->color('success')
                        ->form(
                            [
                                Forms\Components\TextInput::make('amount')
                                    ->label('Deposit Amount')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.01)
                                    ->prefix(BankingDisplay::currencySymbolForForms())
                                    ->helperText('Enter the amount to deposit'),
                                Forms\Components\Textarea::make('reason')
                                    ->label('Reason')
                                    ->required()
                                    ->minLength(20)
                                    ->rows(3)
                                    ->helperText('Provide a reason for this deposit (min. 20 characters)'),
                            ]
                        )
                        ->action(
                            function (Account $record, array $data): void {
                                static::adminActionGovernance()->submitApprovalRequest(
                                    workspace: static::getBackofficeWorkspace(),
                                    action: 'backoffice.accounts.deposit',
                                    reason: $data['reason'],
                                    targetType: Account::class,
                                    targetIdentifier: (string) $record->getKey(),
                                    payload: [
                                        'operation'    => 'deposit',
                                        'asset_code'   => 'USD',
                                        'amount_minor' => (int) round((float) $data['amount'] * 100),
                                        'account_uuid' => $record->uuid,
                                    ],
                                    metadata: [
                                        'mode'            => 'request_approve',
                                        'requester_email' => auth()->user()?->email,
                                    ],
                                );

                                Notification::make()
                                    ->title('Deposit Request Submitted')
                                    ->success()
                                    ->body('Your deposit request has been submitted for approval.')
                                    ->send();
                            }
                        )
                        ->visible(fn (Account $record): bool => ! $record->frozen),
                    Tables\Actions\Action::make('withdraw')
                        ->label('Withdraw')
                        ->icon('heroicon-o-minus-circle')
                        ->color('warning')
                        ->form(
                            [
                                Forms\Components\TextInput::make('amount')
                                    ->label('Withdrawal Amount')
                                    ->numeric()
                                    ->required()
                                    ->minValue(0.01)
                                    ->prefix(BankingDisplay::currencySymbolForForms())
                                    ->helperText('Enter the amount to withdraw'),
                                Forms\Components\Textarea::make('reason')
                                    ->label('Reason')
                                    ->required()
                                    ->minLength(20)
                                    ->rows(3)
                                    ->helperText('Provide a reason for this withdrawal (min. 20 characters)'),
                            ]
                        )
                        ->action(
                            function (Account $record, array $data): void {
                                static::adminActionGovernance()->submitApprovalRequest(
                                    workspace: static::getBackofficeWorkspace(),
                                    action: 'backoffice.accounts.withdraw',
                                    reason: $data['reason'],
                                    targetType: Account::class,
                                    targetIdentifier: (string) $record->getKey(),
                                    payload: [
                                        'operation'             => 'withdraw',
                                        'amount_minor'          => (int) round((float) $data['amount'] * 100),
                                        'current_balance_minor' => $record->getBalance('USD'),
                                        'account_uuid'          => $record->uuid,
                                    ],
                                    metadata: [
                                        'mode'            => 'request_approve',
                                        'requester_email' => auth()->user()?->email,
                                    ],
                                );

                                Notification::make()
                                    ->title('Withdrawal Request Submitted')
                                    ->success()
                                    ->body('Your withdrawal request has been submitted for approval.')
                                    ->send();
                            }
                        )
                        ->visible(fn (Account $record): bool => ! $record->frozen),
                    Tables\Actions\Action::make('freeze')
                        ->label('Freeze')
                        ->icon('heroicon-o-lock-closed')
                        ->color('danger')
                        ->modalHeading('Freeze Account')
                        ->modalSubmitActionLabel('Yes, freeze account')
                        ->form(
                            [
                                Forms\Components\Textarea::make('reason')
                                    ->label('Reason')
                                    ->required()
                                    ->minLength(10)
                                    ->rows(3)
                                    ->helperText('Provide a reason for freezing this account (min. 10 characters)'),
                            ]
                        )
                        ->action(
                            function (Account $record, array $data): void {
                                static::freezeAccount($record, $data['reason']);

                                Notification::make()
                                    ->title('Account Frozen')
                                    ->success()
                                    ->body('The account has been frozen successfully.')
                                    ->send();
                            }
                        )
                        ->visible(fn (Account $record): bool => ! $record->frozen),
                    Tables\Actions\Action::make('unfreeze')
                        ->label('Unfreeze')
                        ->icon('heroicon-o-lock-open')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Unfreeze Account')
                        ->modalDescription('Are you sure you want to unfreeze this account? This will allow transactions again.')
                        ->modalSubmitActionLabel('Yes, unfreeze account')
                        ->action(
                            function (Account $record): void {
                                $oldValues = ['frozen' => true];

                                app(AccountService::class)->unfreeze(
                                    $record->uuid,
                                    reason: 'account_resource_unfreeze',
                                    authorizedBy: auth()->user()?->email,
                                );

                                static::adminActionGovernance()->auditDirectAction(
                                    workspace: static::getBackofficeWorkspace(),
                                    action: 'backoffice.accounts.unfrozen',
                                    reason: 'Account unfrozen from resource table',
                                    auditable: $record,
                                    oldValues: $oldValues,
                                    newValues: ['frozen' => false],
                                    metadata: [
                                        'mode'         => 'direct_elevated',
                                        'workspace'    => 'finance',
                                        'account_uuid' => $record->uuid,
                                        'context'      => 'account_resource',
                                    ],
                                    tags: 'backoffice,finance,accounts'
                                );

                                Notification::make()
                                    ->title('Account Unfrozen')
                                    ->success()
                                    ->body('The account has been unfrozen successfully.')
                                    ->send();
                            }
                        )
                        ->visible(fn (Account $record): bool => $record->frozen),
                ]
            )
            ->bulkActions(
                [
                    Tables\Actions\BulkActionGroup::make(
                        [
                            Tables\Actions\BulkAction::make('freeze')
                                ->label('Freeze Selected')
                                ->icon('heroicon-o-lock-closed')
                                ->color('danger')
                                ->form(
                                    [
                                        Forms\Components\Textarea::make('reason')
                                            ->label('Reason')
                                            ->required()
                                            ->minLength(10)
                                            ->rows(3)
                                            ->helperText('Provide a reason for bulk freezing these accounts'),
                                    ]
                                )
                                ->action(
                                    function (Collection $records, array $data): void {
                                        static::adminActionGovernance()->submitApprovalRequest(
                                            workspace: static::getBackofficeWorkspace(),
                                            action: 'backoffice.accounts.bulk_freeze',
                                            reason: $data['reason'],
                                            payload: [
                                                'requested_state' => 'frozen',
                                                'record_count'    => $records->count(),
                                                'account_uuids'   => $records->map(fn (Account $a): string => $a->uuid)->values()->all(),
                                            ],
                                            metadata: [
                                                'mode' => 'request_approve',
                                            ],
                                        );

                                        Notification::make()
                                            ->title('Bulk Freeze Request Submitted')
                                            ->success()
                                            ->body('The bulk freeze request has been submitted for approval.')
                                            ->send();
                                    }
                                ),
                        ]
                    ),
                ]
            );
    }

    public static function freezeAccount(Account $record, string $reason): void
    {
        static::authorizeWorkspace();

        $oldValues = ['frozen' => false];

        app(AccountService::class)->freeze(
            $record->uuid,
            reason: $reason,
            authorizedBy: auth()->user()?->email,
        );

        static::adminActionGovernance()->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.accounts.frozen',
            reason: $reason,
            auditable: $record,
            oldValues: $oldValues,
            newValues: ['frozen' => true],
            metadata: [
                'mode'         => 'direct_elevated',
                'workspace'    => 'finance',
                'reason'       => $reason,
                'account_uuid' => $record->uuid,
                'context'      => 'account_resource',
            ],
            tags: 'backoffice,finance,accounts'
        );
    }

    public static function adminActionGovernance(): AdminActionGovernance
    {
        return app(AdminActionGovernance::class);
    }

    public static function authorizeWorkspace(): void
    {
        app(BackofficeWorkspaceAccess::class)->authorize(static::getBackofficeWorkspace());
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TransactionsRelationManager::class,
            RelationManagers\TurnoversRelationManager::class,
            RelationManagers\LinkedWalletsRelationManager::class,
            RelationManagers\MinorAccountLifecycleTransitionsRelationManager::class,
            RelationManagers\MinorAccountLifecycleExceptionsRelationManager::class,
        ];
    }

    public static function getWidgets(): array
    {
        return [
            AccountResource\Widgets\AccountStatsOverview::class,
            AccountResource\Widgets\AccountBalanceChart::class,
            AccountResource\Widgets\RecentTransactionsChart::class,
            AccountResource\Widgets\TurnoverTrendChart::class,
            AccountResource\Widgets\AccountGrowthChart::class,
            AccountResource\Widgets\SystemHealthWidget::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAccounts::route('/'),
            'create' => Pages\CreateAccount::route('/create'),
            'edit'   => Pages\EditAccount::route('/{record}/edit'),
            'view'   => Pages\ViewAccount::route('/{record}'),
        ];
    }

    public static function getGlobalSearchResultDetails($record): array
    {
        return [
            'User'    => $record->user_uuid,
            'Balance' => BankingDisplay::minorUnitsAsString($record->balance),
            'Status'  => $record->frozen ? 'Frozen' : 'Active',
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['uuid', 'name', 'user_uuid'];
    }
}
