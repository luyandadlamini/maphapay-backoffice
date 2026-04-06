<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CardIssuanceResource\Pages;

use App\Filament\Admin\Resources\CardIssuanceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCardIssuance extends CreateRecord
{
    protected static string $resource = CardIssuanceResource::class;
}
