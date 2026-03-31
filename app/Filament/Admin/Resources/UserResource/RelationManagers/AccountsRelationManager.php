<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Services\AccountService;
use App\Domain\Account\Workflows\FreezeAccountWorkflow;
use App\Domain\Account\Workflows\UnfreezeAccountWorkflow;
use Exception;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Workflow\WorkflowStub;

class AccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'accounts';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Wallet Accounts';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('balance')
                    ->disabled()
                    ->dehydrated(false),
                Forms\Components\Toggle::make('frozen')
                    ->label('Account Frozen'),
            ]);
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();

        return $table
            ->headerActions([
                Tables\Actions\Action::make('createAccount')
                    ->label('Create Account')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label('Account Name')
                            ->required()
                            ->placeholder(fn () => $owner?->name . "'s Main Account"),
                    ])
                    ->action(function (array $data) use ($owner): void {
                        if (! $owner) {
                            Notification::make()
                                ->title('Error')
                                ->body('Could not determine user context.')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            $accountService = app(AccountService::class);
                            $accountName = $data['name'] ?? $owner->name . "'s Main Account";
                            $accountUuid = $accountService->createForUser($owner->uuid, $accountName);

                            Notification::make()
                                ->title('Account Created')
                                ->success()
                                ->body("Account '{$accountName}' created successfully.")
                                ->send();

                            $this->refresh();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Failed to Create Account')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('account_number')
                    ->label('Account No.')
                    ->copyable()
                    ->copyMessage('Account number copied')
                    ->searchable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('uuid')
                    ->label('Account ID')
                    ->copyable()
                    ->copyMessage('Account ID copied')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')
                    ->label('Account Name')
                    ->sortable()
                    ->weight('bold'),
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
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('frozen')
                    ->label('Status')
                    ->options([
                        '0' => 'Active',
                        '1' => 'Frozen',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('deposit')
                    ->label('Deposit')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Deposit Amount')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->prefix('$')
                            ->helperText('Enter the amount to deposit'),
                    ])
                    ->action(function (Account $record, array $data): void {
                        try {
                            $accountService = app(AccountService::class);
                            $amountInCents = (int) ($data['amount'] * 100);
                            $accountService->depositDirect($record->uuid, $amountInCents, 'Admin deposit to ' . $record->name);

                            Notification::make()
                                ->title('Deposit Successful')
                                ->success()
                                ->body(config('banking.currency_symbol', 'E') . number_format($data['amount'], 2) . ' has been deposited to ' . $record->name)
                                ->send();

                            $this->refresh();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Deposit Failed')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->visible(fn (Account $record): bool => ! $record->frozen),
                Tables\Actions\Action::make('withdraw')
                    ->label('Withdraw')
                    ->icon('heroicon-o-minus-circle')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Withdrawal Amount')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->prefix('$')
                            ->helperText('Enter the amount to withdraw'),
                    ])
                    ->action(function (Account $record, array $data): void {
                        try {
                            $accountService = app(AccountService::class);
                            $amountInCents = (int) ($data['amount'] * 100);
                            $accountService->withdrawDirect($record->uuid, $amountInCents, 'Admin withdrawal from ' . $record->name);

                            Notification::make()
                                ->title('Withdrawal Successful')
                                ->success()
                                ->body(config('banking.currency_symbol', 'E') . number_format($data['amount'], 2) . ' has been withdrawn from ' . $record->name)
                                ->send();

                            $this->refresh();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Withdrawal Failed')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->visible(fn (Account $record): bool => ! $record->frozen && $record->balance > 0),
                Tables\Actions\Action::make('freeze')
                    ->label('Freeze')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Freeze Account')
                    ->modalDescription('Are you sure you want to freeze this account? This will prevent all transactions.')
                    ->modalSubmitActionLabel('Yes, freeze account')
                    ->action(function (Account $record): void {
                        try {
                            $workflow = WorkflowStub::make(FreezeAccountWorkflow::class);
                            $workflow->start(
                                new AccountUuid($record->uuid),
                                'Admin action from user management',
                                auth()->user()->email ?? 'unknown'
                            );

                            Notification::make()
                                ->title('Account Frozen')
                                ->success()
                                ->body('The account has been frozen successfully.')
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Failed to Freeze Account')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->visible(fn (Account $record): bool => ! $record->frozen),
                Tables\Actions\Action::make('unfreeze')
                    ->label('Unfreeze')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Unfreeze Account')
                    ->modalDescription('Are you sure you want to unfreeze this account? This will allow transactions again.')
                    ->modalSubmitActionLabel('Yes, unfreeze account')
                    ->action(function (Account $record): void {
                        try {
                            $workflow = WorkflowStub::make(UnfreezeAccountWorkflow::class);
                            $workflow->start(
                                new AccountUuid($record->uuid),
                                'Admin action from user management',
                                auth()->user()->email ?? 'unknown'
                            );

                            Notification::make()
                                ->title('Account Unfrozen')
                                ->success()
                                ->body('The account has been unfrozen successfully.')
                                ->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Failed to Unfreeze Account')
                                ->danger()
                                ->body($e->getMessage())
                                ->send();
                        }
                    })
                    ->visible(fn (Account $record): bool => $record->frozen),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('freeze')
                        ->label('Freeze Selected')
                        ->icon('heroicon-o-lock-closed')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(function ($records): void {
                            $adminEmail = auth()->user()->email ?? 'unknown';
                            $success = 0;
                            $failed = 0;

                            foreach ($records as $record) {
                                if (! $record->frozen) {
                                    try {
                                        $workflow = WorkflowStub::make(FreezeAccountWorkflow::class);
                                        $workflow->start(
                                            new AccountUuid($record->uuid),
                                            'Admin bulk freeze action',
                                            $adminEmail
                                        );
                                        $success++;
                                    } catch (Exception $e) {
                                        $failed++;
                                    }
                                }
                            }

                            if ($success > 0) {
                                Notification::make()
                                    ->title('Accounts Frozen')
                                    ->success()
                                    ->body("{$success} account(s) frozen successfully.")
                                    ->send();
                            }

                            if ($failed > 0) {
                                Notification::make()
                                    ->title('Some Freezes Failed')
                                    ->warning()
                                    ->body("{$failed} account(s) could not be frozen.")
                                    ->send();
                            }
                        }),
                ]),
            ]);
    }
}
