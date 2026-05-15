<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WalletProviderTransactionResource\Pages;

use App\Filament\Admin\Resources\WalletProviderTransactionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewWalletProviderTransaction extends ViewRecord
{
    protected static string $resource = WalletProviderTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
