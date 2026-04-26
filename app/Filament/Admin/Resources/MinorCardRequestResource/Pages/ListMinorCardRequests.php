<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MinorCardRequestResource\Pages;

use App\Filament\Admin\Resources\MinorCardRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListMinorCardRequests extends ListRecords
{
    protected static string $resource = MinorCardRequestResource::class;
}
