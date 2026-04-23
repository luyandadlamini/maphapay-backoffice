<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MinorFamilyFundingLinkResource\Pages;

use App\Filament\Admin\Resources\MinorFamilyFundingLinkResource;
use Filament\Resources\Pages\ListRecords;

class ListMinorFamilyFundingLinks extends ListRecords
{
    protected static string $resource = MinorFamilyFundingLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
