<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\CardRiskEventResource\Pages;

use App\Filament\Admin\Resources\Cards\CardRiskEventResource;
use Filament\Resources\Pages\ListRecords;

class ListCardRiskEvents extends ListRecords
{
    protected static string $resource = CardRiskEventResource::class;
}
