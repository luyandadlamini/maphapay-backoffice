<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BasketAssetResource\Pages;

use App\Filament\Admin\Resources\BasketAssetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBasketAsset extends CreateRecord
{
    protected static string $resource = BasketAssetResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Add created_by if available
        if (auth()->check()) {
            $data['created_by'] = auth()->user()->uuid;
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        // Create the basket as an asset
        $this->getRecord()->toAsset();

        // Calculate initial value
        app(\App\Domain\Basket\Services\BasketValueCalculationService::class)
            ->calculateValue($this->getRecord());
    }
}
