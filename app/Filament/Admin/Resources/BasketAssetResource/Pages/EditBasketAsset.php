<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BasketAssetResource\Pages;

use App\Filament\Admin\Resources\BasketAssetResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBasketAsset extends EditRecord
{
    protected static string $resource = BasketAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function afterSave(): void
    {
        // Recalculate value after updating
        app(\App\Domain\Basket\Services\BasketValueCalculationService::class)
            ->calculateValue($this->getRecord(), false);
    }
}
