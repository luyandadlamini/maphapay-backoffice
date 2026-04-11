<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AssetResource\Pages;

use App\Filament\Admin\Resources\AssetResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAsset extends ViewRecord
{
    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('requestEdit')
                ->label('Edit Asset')
                ->icon('heroicon-m-pencil-square')
                ->fillForm(function (): array {
                    /** @var \App\Domain\Asset\Models\Asset $record */
                    $record = $this->getRecord();

                    return AssetResource::assetFormData($record);
                })
                ->form(AssetResource::assetChangeRequestSchema())
                ->action(function (array $data): void {
                    /** @var \App\Domain\Asset\Models\Asset $record */
                    $record = $this->getRecord();

                    AssetResource::requestAssetEditApproval($record, $data);
                    $this->dispatch('$refresh');
                }),

            Actions\Action::make('toggle_status')
                ->label(fn () => $this->getRecord()->is_active ? 'Deactivate' : 'Activate')
                ->icon(fn () => $this->getRecord()->is_active ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')
                ->color(fn () => $this->getRecord()->is_active ? 'danger' : 'success')
                ->form(AssetResource::reasonSchema())
                ->action(function (array $data): void {
                    /** @var \App\Domain\Asset\Models\Asset $record */
                    $record = $this->getRecord();

                    AssetResource::requestAssetStatusApproval(
                        record: $record,
                        requestedState: $record->is_active ? 'inactive' : 'active',
                        reason: (string) $data['reason'],
                    );
                })
                ->requiresConfirmation(fn () => $this->getRecord()->is_active)
                ->modalDescription(
                    fn () => $this->getRecord()->is_active
                    ? 'Are you sure you want to deactivate this asset? This will prevent new transactions.'
                    : null
                ),

            Actions\Action::make('delete')
                ->label('Delete Asset')
                ->icon('heroicon-m-trash')
                ->color('danger')
                ->form(AssetResource::reasonSchema())
                ->action(function (array $data): void {
                    /** @var \App\Domain\Asset\Models\Asset $record */
                    $record = $this->getRecord();

                    AssetResource::requestAssetDeletionApproval(
                        record: $record,
                        reason: (string) $data['reason'],
                    );
                })
                ->requiresConfirmation()
                ->modalDescription('Are you sure you want to delete this asset? This action cannot be undone and may affect existing balances.'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AssetResource\Widgets\AssetOverviewWidget::class,
        ];
    }
}
