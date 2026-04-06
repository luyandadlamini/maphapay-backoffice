<?php

declare(strict_types=1);

namespace App\Filament\Resources\CgoPricingRoundResource\Pages;

use App\Domain\Cgo\Models\CgoPricingRound;
use App\Filament\Resources\CgoPricingRoundResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCgoPricingRound extends CreateRecord
{
    protected static string $resource = CgoPricingRoundResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // If this round is set as active, deactivate all other rounds
        if ($data['is_active'] ?? false) {
            CgoPricingRound::where('is_active', true)->update(['is_active' => false]);
        }

        // Set default values
        $data['shares_sold'] = 0;
        $data['total_raised'] = 0;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
