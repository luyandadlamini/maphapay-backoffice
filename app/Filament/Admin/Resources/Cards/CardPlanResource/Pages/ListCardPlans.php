<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\CardPlanResource\Pages;

use App\Filament\Admin\Resources\Cards\CardPlanResource;
use Filament\Resources\Pages\ListRecords;

class ListCardPlans extends ListRecords
{
    protected static string $resource = CardPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action
        ];
    }
}
