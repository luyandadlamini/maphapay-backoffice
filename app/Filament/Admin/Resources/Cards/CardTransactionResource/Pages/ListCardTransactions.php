<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\CardTransactionResource\Pages;

use App\Filament\Admin\Resources\Cards\CardTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListCardTransactions extends ListRecords
{
    protected static string $resource = CardTransactionResource::class;
}
