<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AdjustmentRequestResource\Pages;

use App\Filament\Admin\Resources\AdjustmentRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAdjustmentRequest extends ViewRecord
{
    protected static string $resource = AdjustmentRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
