<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Account\Models\AccountMembership;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AccountResource extends Resource
{
    use HasBackofficeWorkspace;
    use RespectsModuleVisibility;

    protected static ?string $model = AccountMembership::class;

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
        return app(BackofficeWorkspaceAccess::class)->canAccess(static::getBackofficeWorkspace());
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Use AccountMembership (central DB) as the list data source.
     *
     * Account uses UsesTenantConnection so a single cross-tenant query is not
     * possible. AccountMembership is on the central connection and provides all
     * identifiers needed to render the list: account_uuid, tenant_id, user_uuid,
     * account_type, role, and status. Balance and frozen state are omitted from
     * the list — they are visible on the per-record ViewAccount detail page which
     * initialises tenancy correctly.
     *
     * @return Builder<AccountMembership>
     */
    public static function getEloquentQuery(): Builder
    {
        return AccountMembership::query()->with('user');
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
                                Forms\Components\TextInput::make('account_uuid')
                                    ->label('Account UUID')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('display_name')
                                    ->label('Display Name')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('user_uuid')
                                    ->label('User UUID')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->helperText('The unique identifier of the user who owns this account'),
                                Forms\Components\TextInput::make('tenant_id')
                                    ->label('Tenant')
                                    ->disabled()
                                    ->dehydrated(false),
                            ]
                        )->columns(2),

                    Section::make('Membership Details')
                        ->description('Account membership status and role')
                        ->schema(
                            [
                                Forms\Components\TextInput::make('account_type')
                                    ->label('Account Type')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('role')
                                    ->label('Role')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('status')
                                    ->label('Status')
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make('verification_tier')
                                    ->label('Verification Tier')
                                    ->disabled()
                                    ->dehydrated(false),
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
                    Tables\Columns\TextColumn::make('account_uuid')
                        ->label('Account ID')
                        ->copyable()
                        ->copyMessage('Account ID copied')
                        ->copyMessageDuration(1500)
                        ->searchable(),
                    Tables\Columns\TextColumn::make('display_name')
                        ->label('Account Name')
                        ->searchable()
                        ->sortable()
                        ->weight('bold')
                        ->placeholder('—'),
                    Tables\Columns\TextColumn::make('user_uuid')
                        ->label('User ID')
                        ->copyable()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('tenant_id')
                        ->label('Tenant')
                        ->searchable()
                        ->sortable()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('account_type')
                        ->label('Type')
                        ->badge()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('role')
                        ->label('Role')
                        ->badge()
                        ->sortable()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'active'    => 'success',
                            'suspended' => 'danger',
                            'invited'   => 'warning',
                            default     => 'gray',
                        })
                        ->sortable(),
                    Tables\Columns\TextColumn::make('joined_at')
                        ->label('Joined')
                        ->dateTime('M j, Y g:i A')
                        ->sortable()
                        ->toggleable(),
                    Tables\Columns\TextColumn::make('created_at')
                        ->label('Created')
                        ->dateTime('M j, Y g:i A')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ]
            )
            ->defaultSort('created_at', 'desc')
            ->filters(
                [
                    SelectFilter::make('status')
                        ->label('Account Status')
                        ->options(
                            [
                                'active'    => 'Active',
                                'suspended' => 'Suspended',
                                'invited'   => 'Invited',
                                'removed'   => 'Removed',
                            ]
                        ),
                    SelectFilter::make('account_type')
                        ->label('Account Type')
                        ->options(
                            [
                                'personal' => 'Personal',
                                'standard' => 'Standard',
                                'merchant' => 'Merchant',
                                'company'  => 'Company',
                            ]
                        ),
                ]
            )
            ->actions(
                [
                    Tables\Actions\ViewAction::make()
                        ->url(fn (AccountMembership $record): string => static::getUrl('view', ['record' => $record->account_uuid])),
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
                            function (AccountMembership $record, array $data): void {
                                static::adminActionGovernance()->submitApprovalRequest(
                                    workspace: static::getBackofficeWorkspace(),
                                    action: 'backoffice.accounts.deposit',
                                    reason: $data['reason'],
                                    targetType: AccountMembership::class,
                                    targetIdentifier: $record->account_uuid,
                                    payload: [
                                        'operation'    => 'deposit',
                                        'asset_code'   => 'USD',
                                        'amount_minor' => (int) round((float) $data['amount'] * 100),
                                        'account_uuid' => $record->account_uuid,
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
                        ->visible(fn (AccountMembership $record): bool => $record->status === 'active'),
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
                            function (AccountMembership $record, array $data): void {
                                static::adminActionGovernance()->submitApprovalRequest(
                                    workspace: static::getBackofficeWorkspace(),
                                    action: 'backoffice.accounts.withdraw',
                                    reason: $data['reason'],
                                    targetType: AccountMembership::class,
                                    targetIdentifier: $record->account_uuid,
                                    payload: [
                                        'operation'    => 'withdraw',
                                        'amount_minor' => (int) round((float) $data['amount'] * 100),
                                        'account_uuid' => $record->account_uuid,
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
                        ->visible(fn (AccountMembership $record): bool => $record->status === 'active'),
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
                            function (AccountMembership $record, array $data): void {
                                static::freezeAccount($record->account_uuid, $data['reason']);

                                Notification::make()
                                    ->title('Account Frozen')
                                    ->success()
                                    ->body('The account has been frozen successfully.')
                                    ->send();
                            }
                        )
                        ->visible(fn (AccountMembership $record): bool => $record->status === 'active'),
                    Tables\Actions\Action::make('unfreeze')
                        ->label('Unfreeze')
                        ->icon('heroicon-o-lock-open')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Unfreeze Account')
                        ->modalDescription('Are you sure you want to unfreeze this account? This will allow transactions again.')
                        ->modalSubmitActionLabel('Yes, unfreeze account')
                        ->action(
                            function (AccountMembership $record): void {
                                app(AccountService::class)->unfreeze(
                                    $record->account_uuid,
                                    reason: 'account_resource_unfreeze',
                                    authorizedBy: auth()->user()?->email,
                                );

                                static::adminActionGovernance()->auditDirectAction(
                                    workspace: static::getBackofficeWorkspace(),
                                    action: 'backoffice.accounts.unfrozen',
                                    reason: 'Account unfrozen from resource table',
                                    auditable: $record,
                                    oldValues: ['frozen' => true],
                                    newValues: ['frozen' => false],
                                    metadata: [
                                        'mode'         => 'direct_elevated',
                                        'workspace'    => 'finance',
                                        'account_uuid' => $record->account_uuid,
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
                        ->visible(fn (AccountMembership $record): bool => $record->status === 'suspended'),
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
                                                'account_uuids'   => $records->map(fn (AccountMembership $a): string => $a->account_uuid)->values()->all(),
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

    public static function freezeAccount(string $accountUuid, string $reason): void
    {
        static::authorizeWorkspace();

        app(AccountService::class)->freeze(
            $accountUuid,
            reason: $reason,
            authorizedBy: auth()->user()?->email,
        );

        static::adminActionGovernance()->auditDirectAction(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.accounts.frozen',
            reason: $reason,
            auditable: null,
            oldValues: ['frozen' => false],
            newValues: ['frozen' => true],
            metadata: [
                'mode'         => 'direct_elevated',
                'workspace'    => 'finance',
                'reason'       => $reason,
                'account_uuid' => $accountUuid,
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

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var AccountMembership $record */
        return [
            'User'   => $record->user_uuid,
            'Type'   => $record->account_type,
            'Status' => $record->status,
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['account_uuid', 'user_uuid', 'tenant_id', 'display_name'];
    }
}
