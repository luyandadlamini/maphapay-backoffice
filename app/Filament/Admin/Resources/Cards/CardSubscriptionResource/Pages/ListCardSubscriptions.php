<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\CardSubscriptionResource\Pages;

use App\Filament\Admin\Resources\Cards\CardSubscriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCardSubscriptions extends ListRecords
{
    protected static string $resource = CardSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
