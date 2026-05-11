<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\MinorCardSubscriptionResource\Pages;

use App\Filament\Admin\Resources\Cards\MinorCardSubscriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewMinorCardSubscription extends ViewRecord
{
    protected static string $resource = MinorCardSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
