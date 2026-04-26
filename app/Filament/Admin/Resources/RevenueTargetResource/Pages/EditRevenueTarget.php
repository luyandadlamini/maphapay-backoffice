<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RevenueTargetResource\Pages;

use App\Domain\Analytics\Models\RevenueTarget;
use App\Filament\Admin\Resources\RevenueTargetResource;
use App\Filament\Admin\Support\RevenueTargetAudit;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRevenueTarget extends EditRecord
{
    protected static string $resource = RevenueTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof RevenueTarget) {
            return;
        }

        RevenueTargetAudit::recordSaved($record, 'updated');
    }
}
