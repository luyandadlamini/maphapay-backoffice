<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MinorFamilyReconciliationExceptionResource\Pages;

use App\Filament\Admin\Resources\MinorFamilyReconciliationExceptionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMinorFamilyReconciliationException extends ViewRecord
{
    protected static string $resource = MinorFamilyReconciliationExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
