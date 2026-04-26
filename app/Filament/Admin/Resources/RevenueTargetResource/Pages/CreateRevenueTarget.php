<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RevenueTargetResource\Pages;

use App\Domain\Analytics\Models\RevenueTarget;
use App\Filament\Admin\Resources\RevenueTargetResource;
use App\Filament\Admin\Support\RevenueTargetAudit;
use Filament\Resources\Pages\CreateRecord;

class CreateRevenueTarget extends CreateRecord
{
    protected static string $resource = RevenueTargetResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof RevenueTarget) {
            return;
        }

        RevenueTargetAudit::recordSaved($record, 'created');
    }
}
