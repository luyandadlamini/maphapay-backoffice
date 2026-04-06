<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GroupSavingsResource\Pages;

use App\Filament\Admin\Resources\GroupSavingsResource;
use Filament\Resources\Pages\ViewRecord;

class ViewGroupSavings extends ViewRecord
{
    protected static string $resource = GroupSavingsResource::class;

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }
}
