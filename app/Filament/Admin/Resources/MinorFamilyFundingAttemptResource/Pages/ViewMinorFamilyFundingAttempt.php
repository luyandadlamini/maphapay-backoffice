<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MinorFamilyFundingAttemptResource\Pages;

use App\Filament\Admin\Resources\MinorFamilyFundingAttemptResource;
use Filament\Resources\Pages\ViewRecord;

class ViewMinorFamilyFundingAttempt extends ViewRecord
{
    protected static string $resource = MinorFamilyFundingAttemptResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
