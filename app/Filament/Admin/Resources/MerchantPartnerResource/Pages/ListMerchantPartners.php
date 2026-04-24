<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MerchantPartnerResource\Pages;

use App\Filament\Admin\Resources\MerchantPartnerResource;
use Filament\Resources\Pages\ListRecords;

class ListMerchantPartners extends ListRecords
{
    protected static string $resource = MerchantPartnerResource::class;
}
