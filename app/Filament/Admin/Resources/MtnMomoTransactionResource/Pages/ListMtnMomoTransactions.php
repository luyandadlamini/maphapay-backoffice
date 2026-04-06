<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MtnMomoTransactionResource\Pages;

use App\Filament\Admin\Resources\MtnMomoTransactionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMtnMomoTransactions extends ListRecords
{
    protected static string $resource = MtnMomoTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No create actions
        ];
    }
}
