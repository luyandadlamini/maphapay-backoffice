<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Shared\Services\OtpService;
use App\Filament\Admin\Resources\UserResource\Pages;
use App\Filament\Admin\Resources\UserResource\RelationManagers\AccountsRelationManager;
use App\Filament\Admin\Resources\UserResource\RelationManagers\BankAccountsRelationManager;
use App\Filament\Admin\Resources\UserResource\RelationManagers\KycStatusRelationManager;
use App\Filament\Admin\Resources\UserResource\RelationManagers\TransactionsRelationManager;
use App\Models\User;
use App\Models\UserOtp;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Throwable;

class UserResource extends Resource
{
    use \App\Filament\Admin\Traits\RespectsModuleVisibility;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

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
                ]
            );
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
                        ->searchable(),
                    Tables\Columns\BadgeColumn::make('kyc_status')
                        ->label('KYC')
                        ->colors([
                            'warning' => 'not_started',
                            'info'    => 'pending',
                            'success' => 'approved',
                            'danger'  => 'rejected',
                        ])
                        ->sortable(),
                    Tables\Columns\TextColumn::make('accounts_sum_balance')
                        ->label('Total Balance')
                        ->money('USD', 100)
                        ->sum('accounts', 'balance')
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
                        ->toggleable(),
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
                        ->visible(fn (User $record): bool => (bool) $record->mobile),
                    Tables\Actions\Action::make('resetPassword')
                        ->label('Reset Password')
                        ->icon('heroicon-o-key')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading('Reset User Password')
                        ->modalDescription('This will send a password reset link to the user\'s email address.')
                        ->modalSubmitActionLabel('Send Reset Link')
                        ->action(function (User $record): void {
                            try {
                                $record->sendPasswordResetNotification(
                                    app(\Illuminate\Auth\Passwords\PasswordBroker::class)->createToken($record)
                                );

                                Notification::make()
                                    ->title('Password Reset Sent')
                                    ->success()
                                    ->body('A password reset link has been sent to ' . $record->email)
                                    ->send();
                            } catch (Throwable $e) {
                                Notification::make()
                                    ->title('Failed to Send Reset')
                                    ->danger()
                                    ->body($e->getMessage())
                                    ->send();
                            }
                        }),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                ]
            )
            ->bulkActions(
                [
                    Tables\Actions\BulkActionGroup::make(
                        [
                            Tables\Actions\DeleteBulkAction::make(),
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
}
