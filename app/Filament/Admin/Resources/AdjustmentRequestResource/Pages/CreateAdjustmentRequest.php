<?php

namespace App\Filament\Admin\Resources\AdjustmentRequestResource\Pages;

use App\Filament\Admin\Resources\AdjustmentRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdjustmentRequest extends CreateRecord
{
    protected static string $resource = AdjustmentRequestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['requester_id'] = auth()->id();
        $data['status'] = 'pending';

        return $data;
    }
}
