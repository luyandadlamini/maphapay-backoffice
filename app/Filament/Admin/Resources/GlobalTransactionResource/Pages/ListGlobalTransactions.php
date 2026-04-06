<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GlobalTransactionResource\Pages;

use App\Filament\Admin\Resources\GlobalTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListGlobalTransactions extends ListRecords
{
    protected static string $resource = GlobalTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create action
        ];
    }
}
