<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Account\Models\AccountBalance;
use App\Domain\Shared\Services\OtpService;
use App\Filament\Admin\Concerns\HasBackofficeWorkspace;
use App\Filament\Admin\Concerns\MasksPii;
use App\Filament\Admin\Resources\UserResource\Pages;
use App\Filament\Admin\Resources\UserResource\RelationManagers\AccountsRelationManager;
use App\Filament\Admin\Resources\UserResource\RelationManagers\BankAccountsRelationManager;
use App\Filament\Admin\Resources\UserResource\RelationManagers\CardsRelationManager;
use App\Filament\Admin\Resources\UserResource\RelationManagers\KycStatusRelationManager;
use App\Filament\Admin\Resources\UserResource\RelationManagers\PocketsRelationManager;
use App\Filament\Admin\Resources\UserResource\RelationManagers\ReferralsRelationManager;
use App\Filament\Admin\Resources\UserResource\RelationManagers\RewardProfilesRelationManager;
use App\Filament\Admin\Resources\UserResource\RelationManagers\SupportCasesRelationManager;
use App\Filament\Admin\Resources\UserResource\RelationManagers\TransactionsRelationManager;
use App\Filament\Admin\Resources\UserResource\RelationManagers\UserAuditLogRelationManager;
use App\Filament\Admin\Traits\RespectsModuleVisibility;
use App\Models\User;
use App\Models\UserOtp;
use App\Support\Backoffice\AdminActionGovernance;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class UserResource extends Resource
{
    use HasBackofficeWorkspace;
    use MasksPii;
    use RespectsModuleVisibility;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Support Hub';

    protected static ?int $navigationSort = 10;

    protected static string $backofficeWorkspace = 'support';

    public static function canViewAny(): bool
    {
        return static::userCanViewUsers();
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
                    Forms\Components\TextInput::make('uuid')
                        ->label('UUID')
                        ->required(),
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    Forms\Components\DateTimePicker::make('email_verified_at'),
                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Textarea::make('two_factor_secret')
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('two_factor_recovery_codes')
                        ->columnSpanFull(),
                    Forms\Components\DateTimePicker::make('two_factor_confirmed_at'),
                    Forms\Components\TextInput::make('current_team_id')
                        ->numeric()
                        ->default(null),
                    Forms\Components\TextInput::make('profile_photo_path')
                        ->maxLength(2048)
                        ->default(null),
                    Forms\Components\TextInput::make('stripe_id')
                        ->maxLength(255)
                        ->default(null),
                    Forms\Components\TextInput::make('pm_type')
                        ->maxLength(255)
                        ->default(null),
                    Forms\Components\TextInput::make('pm_last_four')
                        ->maxLength(4)
                        ->default(null),
                    Forms\Components\DateTimePicker::make('trial_ends_at'),
                    Forms\Components\Section::make('Money Movement Security')
                        ->description('Manage per-user send-money step-up behavior without changing compliance hard limits.')
                        ->schema([
                            Forms\Components\TextInput::make('send_money_step_up_threshold_override')
                                ->label('Send Money Step-Up Threshold Override')
                                ->numeric()
                                ->step(0.01)
                                ->minValue(0)
                                ->placeholder('Use global threshold matrix')
                                ->helperText('Leave blank to use the app-wide threshold derived from this user’s risk rating and KYC level.'),
                            Forms\Components\Textarea::make('send_money_step_up_threshold_override_reason')
                                ->label('Override Reason')
                                ->rows(3)
                                ->maxLength(1000)
                                ->helperText('Document why this user needs a different verification threshold.'),
                            Forms\Components\Placeholder::make('send_money_step_up_threshold_override_updated_at_display')
                                ->label('Override Last Updated')
                                ->content(fn (?User $record): string => $record?->send_money_step_up_threshold_override_updated_at?->toDateTimeString() ?? 'Not set'),
                            Forms\Components\Placeholder::make('send_money_step_up_threshold_override_updated_by_display')
                                ->label('Override Updated By')
                                ->content(fn (?User $record): string => $record?->send_money_step_up_threshold_override_updated_by ?? 'Not set'),
                        ])
                        ->columns(1),
                ]
            );
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Customer 360 Profile')
                    ->description('Comprehensive view of the user\'s personal and security status.')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('name')->label('Full Name'),
                            TextEntry::make('email')->label('Email Address')
                                ->formatStateUsing(fn ($state) => static::maskEmail($state)),
                            TextEntry::make('mobile')->label('Mobile Number')
                                ->formatStateUsing(fn ($state) => static::maskPhone($state)),
                            TextEntry::make('kyc_status')
                                ->label('KYC Status')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'not_started' => 'warning',
                                    'pending'     => 'info',
                                    'approved'    => 'success',
                                    'rejected'    => 'danger',
                                    default       => 'gray',
                                }),
                            IconEntry::make('frozen_at')
                                ->label('Account Frozen')
                                ->boolean()
                                ->trueIcon('heroicon-o-lock-closed')
                                ->falseIcon('heroicon-o-check-circle')
                                ->trueColor('danger')
                                ->falseColor('success'),
                            TextEntry::make('frozen_reason')->label('Reason'),
                            TextEntry::make('uuid')->label('System UUID')->copyable(),
                            TextEntry::make('created_at')->dateTime()->label('Joined At'),
                            IconEntry::make('email_verified_at')
                                ->label('Email Verified')
                                ->boolean(),
                            IconEntry::make('two_factor_confirmed_at')
                                ->label('2FA Enabled')
                                ->boolean(),
                            TextEntry::make('send_money_step_up_threshold_override')
                                ->label('Send Money Auth Override')
                                ->money('SZL'),
                        ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(
                [
                    Tables\Columns\TextColumn::make('uuid')
                        ->label('UUID')
                        ->copyable()
                        ->searchable(),
                    Tables\Columns\TextColumn::make('name')
                        ->searchable(),
                    Tables\Columns\TextColumn::make('email')
                        ->searchable()
                        ->formatStateUsing(fn ($state) => static::maskEmail($state)),
                    Tables\Columns\TextColumn::make('kyc_status')
                        ->label('KYC')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'not_started' => 'warning',
                            'pending'     => 'info',
                            'approved'    => 'success',
                            'rejected'    => 'danger',
                            default       => 'gray',
                        })
                        ->sortable(),
                    Tables\Columns\IconColumn::make('frozen_at')
                        ->label('Status')
                        ->boolean()
                        ->trueIcon('heroicon-o-lock-closed')
                        ->falseIcon('heroicon-o-check-circle')
                        ->trueColor('danger')
                        ->falseColor('success')
                        ->tooltip(fn (User $record): string => $record->frozen_at
                            ? 'Frozen: ' . ($record->frozen_reason ?? 'No reason')
                            : 'Active'),
                    Tables\Columns\TextColumn::make('accounts_sum_balance')
                        ->label('Total Balance')
                        ->money('SZL', 100)
                        ->state(function (?User $record): ?int {
                            if ($record === null) {
                                return null;
                            }

                            $accountUuids = $record->accounts()->pluck('uuid');
                            if ($accountUuids->isEmpty()) {
                                return 0;
                            }

                            return (int) AccountBalance::query()
                                ->whereIn('account_uuid', $accountUuids)
                                ->where('asset_code', config('banking.default_currency', 'SZL'))
                                ->sum('balance');
                        })
                        ->color(fn ($state): string => ($state ?? 0) < 0 ? 'danger' : 'success')
                        ->weight('bold'),
                    Tables\Columns\TextColumn::make('accounts_count')
                        ->label('Accounts')
                        ->counts('accounts'),
                    Tables\Columns\IconColumn::make('email_verified_at')
                        ->label('Email Verified')
                        ->boolean()
                        ->trueIcon('heroicon-o-check-badge')
                        ->falseIcon('heroicon-o-x-mark')
                        ->trueColor('success')
                        ->falseColor('danger'),
                    Tables\Columns\IconColumn::make('two_factor_confirmed_at')
                        ->label('2FA')
                        ->boolean()
                        ->trueIcon('heroicon-o-check-badge')
                        ->falseIcon('heroicon-o-x-mark')
                        ->trueColor('success')
                        ->falseColor('gray'),
                    Tables\Columns\TextColumn::make('mobile')
                        ->label('Mobile')
                        ->toggleable()
                        ->formatStateUsing(fn ($state) => static::maskPhone($state)),
                    Tables\Columns\TextColumn::make('national_id_number')
                        ->label('National ID')
                        ->toggleable(isToggledHiddenByDefault: true)
                        ->formatStateUsing(fn ($state) => static::maskNationalId($state)),
                    Tables\Columns\TextColumn::make('send_money_step_up_threshold_override')
                        ->label('Send Money Override')
                        ->money('SZL')
                        ->toggleable(isToggledHiddenByDefault: true),
                    Tables\Columns\TextColumn::make('created_at')
                        ->dateTime()
                        ->sortable(),
                ]
            )
            ->filters(
                [
                    Tables\Filters\SelectFilter::make('kyc_status')
                        ->label('KYC Status')
                        ->options([
                            'not_started' => 'Not Started',
                            'pending'     => 'Pending',
                            'approved'    => 'Approved',
                            'rejected'    => 'Rejected',
                        ]),
                ]
            )
            ->actions(
                [
                    Tables\Actions\Action::make('resend_otp')
                        ->label('Resend OTP')
                        ->icon('heroicon-o-device-phone-mobile')
                        ->color('warning')
                        ->requiresConfirmation(false)
                        ->form([
                            Forms\Components\Select::make('otp_type')
                                ->label('OTP Type')
                                ->options([
                                    UserOtp::TYPE_LOGIN               => 'Login',
                                    UserOtp::TYPE_MOBILE_VERIFICATION => 'Mobile Verification',
                                    UserOtp::TYPE_PIN_RESET           => 'PIN Reset',
                                ])
                                ->default(UserOtp::TYPE_LOGIN)
                                ->required(),
                        ])
                        ->action(function (User $record, array $data): void {
                            if (! $record->dial_code || ! $record->mobile) {
                                Notification::make()
                                    ->title('No mobile number')
                                    ->body('This user has no mobile number on record.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            try {
                                /** @var OtpService $otpService */
                                $otpService = app(OtpService::class);
                                $otpService->generateAndSend($record, $data['otp_type']);

                                Notification::make()
                                    ->title('OTP sent')
                                    ->body("A new {$data['otp_type']} OTP has been dispatched to {$record->dial_code}{$record->mobile}.")
                                    ->success()
                                    ->send();
                            } catch (Throwable $e) {
                                Notification::make()
                                    ->title('Failed to send OTP')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->visible(fn (User $record): bool => (bool) $record->mobile && static::userCanResendOtp()),
                    Tables\Actions\Action::make('freeze')
                        ->label('Freeze User')
                        ->icon('heroicon-o-lock-closed')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Freeze User Account')
                        ->modalDescription('This will prevent the user from logging in and performing any transactions.')
                        ->modalSubmitActionLabel('Yes, freeze user')
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Reason for freezing')
                                ->required()
                                ->maxLength(500),
                        ])
                        ->action(function (User $record, array $data): void {
                            static::requestUserStateApproval(
                                record: $record,
                                requestedState: 'frozen',
                                reason: (string) $data['reason'],
                            );

                            Notification::make()
                                ->title('User freeze request submitted')
                                ->warning()
                                ->send();
                        })
                        ->visible(fn (User $record): bool => ! $record->isFrozen() && static::userCanRequestFreezeActions()),
                    Tables\Actions\Action::make('unfreeze')
                        ->label('Unfreeze User')
                        ->icon('heroicon-o-lock-open')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Unfreeze User Account')
                        ->modalDescription('This will allow the user to log in and perform transactions again.')
                        ->modalSubmitActionLabel('Yes, unfreeze user')
                        ->form([
                            Forms\Components\Textarea::make('reason')
                                ->label('Reason for unfreezing')
                                ->required()
                                ->maxLength(500),
                        ])
                        ->action(function (User $record, array $data): void {
                            static::requestUserStateApproval(
                                record: $record,
                                requestedState: 'active',
                                reason: (string) $data['reason'],
                            );

                            Notification::make()
                                ->title('User unfreeze request submitted')
                                ->warning()
                                ->send();
                        })
                        ->visible(fn (User $record): bool => $record->isFrozen() && static::userCanRequestFreezeActions()),
                    Tables\Actions\ViewAction::make(),
                ]
            )
            ->bulkActions(
                [
                    Tables\Actions\BulkActionGroup::make(
                        [
                            Tables\Actions\BulkAction::make('requestDelete')
                                ->label('Delete Users')
                                ->icon('heroicon-o-trash')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->form([
                                    Forms\Components\Textarea::make('reason')
                                        ->required()
                                        ->minLength(10),
                                ])
                                ->visible(fn (): bool => static::userCanRequestUserDeletion())
                                ->action(function (Collection $records, array $data): void {
                                    static::requestBulkUserDeletionApproval(
                                        records: $records,
                                        reason: (string) $data['reason'],
                                    );

                                    Notification::make()
                                        ->title('User deletion request submitted')
                                        ->warning()
                                        ->send();
                                }),
                            Tables\Actions\BulkAction::make('approveKyc')
                                ->label('Approve KYC')
                                ->icon('heroicon-o-check-badge')
                                ->color('success')
                                ->requiresConfirmation()
                                ->modalHeading('Approve KYC')
                                ->modalDescription('Are you sure you want to approve KYC for the selected user(s)?')
                                ->modalSubmitActionLabel('Yes, approve')
                                ->form([
                                    Forms\Components\Textarea::make('reason')
                                        ->required()
                                        ->minLength(10),
                                ])
                                ->visible(fn (): bool => static::userCanApproveKyc())
                                ->action(function (Collection $records, array $data): void {
                                    static::requestBulkKycApproval(
                                        records: $records,
                                        approved: true,
                                        reason: (string) $data['reason'],
                                    );

                                    Notification::make()
                                        ->title('KYC approval request submitted')
                                        ->warning()
                                        ->send();
                                }),
                            Tables\Actions\BulkAction::make('rejectKyc')
                                ->label('Reject KYC')
                                ->icon('heroicon-o-x-mark')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalHeading('Reject KYC')
                                ->modalDescription('Are you sure you want to reject KYC for the selected user(s)?')
                                ->modalSubmitActionLabel('Yes, reject')
                                ->form([
                                    Forms\Components\Textarea::make('reason')
                                        ->label('Rejection Reason')
                                        ->required()
                                        ->placeholder('Enter the reason for rejection'),
                                ])
                                ->visible(fn (): bool => static::userCanRejectKyc())
                                ->action(function (Collection $records, array $data): void {
                                    static::requestBulkKycApproval(
                                        records: $records,
                                        approved: false,
                                        reason: (string) $data['reason'],
                                    );

                                    Notification::make()
                                        ->title('KYC rejection request submitted')
                                        ->warning()
                                        ->send();
                                }),
                        ]
                    ),
                ]
            );
    }

    public static function getRelations(): array
    {
        return [
            AccountsRelationManager::class,
            TransactionsRelationManager::class,
            BankAccountsRelationManager::class,
            KycStatusRelationManager::class,
            RewardProfilesRelationManager::class,
            CardsRelationManager::class,
            PocketsRelationManager::class,
            ReferralsRelationManager::class,
            SupportCasesRelationManager::class,
            UserAuditLogRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view'   => Pages\ViewUser::route('/{record}'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function requestUserStateApproval(User $record, string $requestedState, string $reason): void
    {
        $actorEmail = auth()->user()->email ?? 'system';

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: sprintf('backoffice.users.%s', $requestedState === 'frozen' ? 'freeze' : 'unfreeze'),
            reason: $reason,
            targetType: User::class,
            targetIdentifier: $record->uuid,
            payload: [
                'user_uuid' => $record->uuid,
                'user_email' => $record->email,
                'current_state' => $record->isFrozen() ? 'frozen' : 'active',
                'requested_state' => $requestedState,
            ],
            metadata: [
                'mode' => 'request_approve',
                'actor_email' => $actorEmail,
            ],
        );
    }

    /** @param Collection<int, User> $records */
    public static function requestBulkKycApproval(Collection $records, bool $approved, string $reason): void
    {
        $actorEmail = auth()->user()->email ?? 'system';

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: sprintf('backoffice.users.bulk_kyc_%s', $approved ? 'approve' : 'reject'),
            reason: $reason,
            payload: [
                'requested_state' => $approved ? 'approved' : 'rejected',
                'record_count' => $records->count(),
                'user_uuids' => $records->map(fn (User $u): string => (string) $u->uuid)->values()->all(),
                'user_emails' => $records->map(fn (User $u): string => (string) $u->email)->values()->all(),
                'reason' => $reason,
            ],
            metadata: [
                'mode' => 'request_approve',
                'actor_email' => $actorEmail,
            ],
        );
    }

    public static function requestUserDeletionApproval(User $record, string $reason): void
    {
        $actorEmail = auth()->user()->email ?? 'system';

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.users.delete',
            reason: $reason,
            targetType: User::class,
            targetIdentifier: $record->uuid,
            payload: [
                'user_uuid' => $record->uuid,
                'user_email' => $record->email,
                'requested_state' => 'deleted',
            ],
            metadata: [
                'mode' => 'request_approve',
                'actor_email' => $actorEmail,
            ],
        );
    }

    /** @param Collection<int, User> $records */
    public static function requestBulkUserDeletionApproval(Collection $records, string $reason): void
    {
        $actorEmail = auth()->user()->email ?? 'system';

        static::adminActionGovernance()->submitApprovalRequest(
            workspace: static::getBackofficeWorkspace(),
            action: 'backoffice.users.bulk_delete',
            reason: $reason,
            payload: [
                'requested_state' => 'deleted',
                'record_count' => $records->count(),
                'user_uuids' => $records->map(fn (User $u): string => (string) $u->uuid)->values()->all(),
                'user_emails' => $records->map(fn (User $u): string => (string) $u->email)->values()->all(),
            ],
            metadata: [
                'mode' => 'request_approve',
                'actor_email' => $actorEmail,
            ],
        );
    }

    public static function adminActionGovernance(): AdminActionGovernance
    {
        return app(AdminActionGovernance::class);
    }

    public static function userCanViewUsers(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('view-users') || $user->hasRole('super-admin'));
    }

    public static function userCanResendOtp(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('resend-otp') || $user->hasRole('super-admin'));
    }

    public static function userCanResetCredentials(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('reset-user-password') || $user->hasRole('super-admin'));
    }

    public static function userCanRequestFreezeActions(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('freeze-users') || $user->hasRole('super-admin'));
    }

    public static function userCanApproveKyc(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('approve-kyc') || $user->hasRole('super-admin'));
    }

    public static function userCanRejectKyc(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('reject-kyc') || $user->hasRole('super-admin'));
    }

    public static function userCanRequestUserDeletion(): bool
    {
        return auth()->user()?->hasRole('super-admin') ?? false;
    }
}
