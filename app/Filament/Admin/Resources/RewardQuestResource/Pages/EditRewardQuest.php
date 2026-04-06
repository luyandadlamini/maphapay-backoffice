<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RewardQuestResource\Pages;

use App\Filament\Admin\Resources\RewardQuestResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRewardQuest extends EditRecord
{
    protected static string $resource = RewardQuestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
