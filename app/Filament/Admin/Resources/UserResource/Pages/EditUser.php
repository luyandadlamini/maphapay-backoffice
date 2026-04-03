<?php

namespace App\Filament\Admin\Resources\UserResource\Pages;

use App\Filament\Admin\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $originalOverride = $this->record->send_money_step_up_threshold_override;
        $originalReason = $this->record->send_money_step_up_threshold_override_reason;

        $overrideChanged = (string) ($data['send_money_step_up_threshold_override'] ?? '') !== (string) ($originalOverride ?? '')
            || (string) ($data['send_money_step_up_threshold_override_reason'] ?? '') !== (string) ($originalReason ?? '');

        if ($overrideChanged) {
            $data['send_money_step_up_threshold_override_updated_at'] = now();
            $data['send_money_step_up_threshold_override_updated_by'] = auth()->user()?->email ?? 'system';
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
