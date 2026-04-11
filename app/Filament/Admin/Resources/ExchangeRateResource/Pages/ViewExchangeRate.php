<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExchangeRateResource\Pages;

use App\Filament\Admin\Resources\ExchangeRateResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewExchangeRate extends ViewRecord
{
    protected static string $resource = ExchangeRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh Rate')
                ->icon('heroicon-m-arrow-path')
                ->color('warning')
                ->form([
                    \Filament\Forms\Components\Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->minLength(10),
                ])
                ->action(function (array $data): void {
                    /** @var \App\Domain\Asset\Models\ExchangeRate $record */
                    $record = $this->getRecord();

                    ExchangeRateResource::refreshExchangeRate(
                        record: $record,
                        reason: (string) $data['reason'],
                    );
                    $this->dispatch('$refresh');
                })
                ->requiresConfirmation()
                ->visible(fn () => $this->getRecord()->source !== 'manual'),

            Actions\Action::make('toggle_status')
                ->label(fn () => $this->getRecord()->is_active ? 'Deactivate' : 'Activate')
                ->icon(fn () => $this->getRecord()->is_active ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')
                ->color(fn () => $this->getRecord()->is_active ? 'danger' : 'success')
                ->form([
                    \Filament\Forms\Components\Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->minLength(10),
                ])
                ->action(function (array $data): void {
                    /** @var \App\Domain\Asset\Models\ExchangeRate $record */
                    $record = $this->getRecord();

                    ExchangeRateResource::requestExchangeRateStatusApproval(
                        record: $record,
                        requestedState: $record->is_active ? 'inactive' : 'active',
                        reason: (string) $data['reason'],
                    );
                })
                ->requiresConfirmation(fn () => $this->getRecord()->is_active),

            Actions\Action::make('delete')
                ->label('Delete Rate')
                ->icon('heroicon-m-trash')
                ->color('danger')
                ->form([
                    \Filament\Forms\Components\Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->minLength(10),
                ])
                ->action(function (array $data): void {
                    /** @var \App\Domain\Asset\Models\ExchangeRate $record */
                    $record = $this->getRecord();

                    ExchangeRateResource::requestExchangeRateDeletionApproval(
                        record: $record,
                        reason: (string) $data['reason'],
                    );
                })
                ->requiresConfirmation(),
        ];
    }
}
