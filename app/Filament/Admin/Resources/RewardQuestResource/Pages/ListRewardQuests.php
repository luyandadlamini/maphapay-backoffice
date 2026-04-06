<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RewardQuestResource\Pages;

use App\Filament\Admin\Resources\RewardQuestResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRewardQuests extends ListRecords
{
    protected static string $resource = RewardQuestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
