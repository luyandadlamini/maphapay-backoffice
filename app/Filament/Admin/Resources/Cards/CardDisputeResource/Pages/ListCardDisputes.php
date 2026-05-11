<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\CardDisputeResource\Pages;

use App\Filament\Admin\Resources\Cards\CardDisputeResource;
use Filament\Resources\Pages\ListRecords;

class ListCardDisputes extends ListRecords
{
    protected static string $resource = CardDisputeResource::class;
}
