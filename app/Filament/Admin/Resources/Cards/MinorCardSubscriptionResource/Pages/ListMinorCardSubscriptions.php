<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\MinorCardSubscriptionResource\Pages;

use App\Filament\Admin\Resources\Cards\MinorCardSubscriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMinorCardSubscriptions extends ListRecords
{
    protected static string $resource = MinorCardSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
