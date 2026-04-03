<?php

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Filament\Admin\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['send_money_step_up_threshold_override']) || ! empty($data['send_money_step_up_threshold_override_reason'])) {
            $data['send_money_step_up_threshold_override_updated_at'] = now();
            $data['send_money_step_up_threshold_override_updated_by'] = auth()->user()?->email ?? 'system';
        }

        return $data;
    }
}
