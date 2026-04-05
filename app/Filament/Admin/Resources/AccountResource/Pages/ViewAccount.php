<?php

namespace App\Filament\Admin\Resources\AccountResource\Pages;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Services\AccountService;
use App\Filament\Admin\Resources\AccountResource;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

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
                    } catch (\Throwable $e) {
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
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Failed to Unfreeze Wallet')
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
