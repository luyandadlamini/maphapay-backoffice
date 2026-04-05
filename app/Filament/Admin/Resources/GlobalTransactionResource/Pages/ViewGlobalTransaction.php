<?php

namespace App\Filament\Admin\Resources\GlobalTransactionResource\Pages;

use App\Filament\Admin\Resources\GlobalTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewGlobalTransaction extends ViewRecord
{
    protected static string $resource = GlobalTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No edit actions for transactions
        ];
    }
}
