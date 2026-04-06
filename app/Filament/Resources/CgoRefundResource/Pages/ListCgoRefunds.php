<?php

declare(strict_types=1);

namespace App\Filament\Resources\CgoRefundResource\Pages;

use App\Filament\Resources\CgoRefundResource;
use Filament\Resources\Pages\ListRecords;

class ListCgoRefunds extends ListRecords
{
    protected static string $resource = CgoRefundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Refunds are created through the investment resource
        ];
    }
}
