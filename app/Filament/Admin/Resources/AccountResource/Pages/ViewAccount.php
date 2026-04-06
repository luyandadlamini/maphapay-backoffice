<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\Pages;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AdjustmentRequest;
use App\Domain\Account\Projectors\AccountProjector;
use App\Domain\Account\Projectors\TransactionProjector;
use App\Domain\Account\Services\AccountService;
use App\Filament\Admin\Resources\AccountResource;
use App\Filament\Admin\Resources\AdjustmentRequestResource;
use App\Models\User;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Spatie\EventSourcing\Facades\Projectionist;
use Throwable;

class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('freeze')
                ->label('Freeze Wallet')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Freeze Wallet Account')
                ->modalDescription('This will immediately prevent all transactions on this wallet. The customer will not be able to send or receive funds.')
                ->modalSubmitActionLabel('Yes, freeze wallet')
                ->form([
                    Textarea::make('reason')
                        ->label('Freeze Reason')
                        ->required()
                        ->placeholder('e.g. Compliance hold — pending fraud investigation'),
                ])
                ->visible(fn (Account $record): bool => ! $record->frozen
                    && (auth()->user()->can('freeze-accounts') || auth()->user()->hasRole('super-admin')))
                ->action(function (Account $record, array $data): void {
                    try {
                        app(AccountService::class)->freeze($record->uuid);
                        $record->update(['frozen_reason' => $data['reason'], 'frozen_at' => now()]);

                        Notification::make()
                            ->title('Wallet Frozen')
                            ->body("Account {$record->uuid} has been frozen.")
                            ->warning()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Failed to Freeze Wallet')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('unfreeze')
                ->label('Unfreeze Wallet')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Unfreeze Wallet Account')
                ->modalDescription('This will restore full transaction capability for this wallet.')
                ->modalSubmitActionLabel('Yes, unfreeze wallet')
                ->visible(fn (Account $record): bool => $record->frozen
                    && (auth()->user()->can('unfreeze-accounts') || auth()->user()->hasRole('super-admin')))
                ->action(function (Account $record): void {
                    try {
                        app(AccountService::class)->unfreeze($record->uuid);
                        $record->update(['frozen_reason' => null, 'frozen_at' => null]);

                        Notification::make()
                            ->title('Wallet Unfrozen')
                            ->body("Account {$record->uuid} has been unfrozen.")
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Failed to Unfreeze Wallet')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('requestAdjustment')
                ->label('Request Adjustment')
                ->icon('heroicon-o-banknotes')
                ->color('warning')
                ->requiresConfirmation(false)
                ->form([
                    Forms\Components\Select::make('type')
                        ->options(['credit' => 'Credit (Add Funds)', 'debit' => 'Debit (Remove Funds)'])
                        ->required(),
                    Forms\Components\TextInput::make('amount')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0.01)
                        ->required(),
                    Textarea::make('reason')
                        ->required()
                        ->minLength(10)
                        ->rows(3),
                    Forms\Components\FileUpload::make('attachment')
                        ->label('Supporting Document')
                        ->disk('private')
                        ->directory('adjustment-attachments')
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->nullable(),
                ])
                ->action(function (Account $record, array $data): void {
                    AdjustmentRequest::create([
                        'account_id'      => $record->id,
                        'requester_id'    => auth()->id(),
                        'type'            => $data['type'],
                        'amount'          => $data['amount'],
                        'reason'          => $data['reason'],
                        'attachment_path' => $data['attachment'] ?? null,
                        'status'          => 'pending',
                    ]);

                    $financeLeads = User::role('finance-lead')->get();

                    foreach ($financeLeads as $lead) {
                        /** @var User $lead */
                        Notification::make()
                            ->title('New Adjustment Request Pending')
                            ->body("A ledger adjustment for account #{$record->id} requires your approval.")
                            ->warning()
                            ->actions([
                                NotificationAction::make('review')
                                    ->label('Review')
                                    ->url(AdjustmentRequestResource::getUrl('index')),
                            ])
                            ->sendToDatabase($lead);
                    }

                    Notification::make()
                        ->title('Adjustment request submitted')
                        ->body('Finance leads have been notified.')
                        ->success()
                        ->send();
                })
                ->visible(fn (): bool => auth()->user()?->can('request-adjustments') ?? false),

            Action::make('replayProjector')
                ->label('Replay Projector')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Replay Account Projector')
                ->modalDescription('This rebuilds this account\'s projected balance from the event stream. Use only if the balance appears incorrect.')
                ->modalSubmitActionLabel('Yes, replay projector')
                ->form([
                    Textarea::make('reason')
                        ->label('Reason for Replay')
                        ->required()
                        ->placeholder('Explain why the projector needs to be rebuilt'),
                ])
                ->visible(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false)
                ->action(function (Account $record, array $data): void {
                    try {
                        $projectors = [
                            TransactionProjector::class,
                            AccountProjector::class,
                        ];

                        foreach ($projectors as $projectorClass) {
                            try {
                                Projectionist::replayEvents($projectorClass);
                            } catch (Throwable $e) {
                            }
                        }

                        Notification::make()
                            ->title('Projector replay queued')
                            ->body("The account projectors for {$record->account_number} will be rebuilt.")
                            ->warning()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Projector replay failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\EditAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AccountResource\Widgets\AccountStatsOverview::class,
        ];
    }
}
