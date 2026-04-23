<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MinorFamilySupportTransferResource\Pages;

use App\Filament\Admin\Resources\MinorFamilySupportTransferResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMinorFamilySupportTransfer extends ViewRecord
{
    protected static string $resource = MinorFamilySupportTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
