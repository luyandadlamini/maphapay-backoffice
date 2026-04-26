<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RevenueTargetResource\Pages;

use App\Filament\Admin\Resources\RevenueTargetResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRevenueTargets extends ListRecords
{
    protected static string $resource = RevenueTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
