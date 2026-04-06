<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DataSubjectRequestResource\Pages;

use App\Filament\Admin\Resources\DataSubjectRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListDataSubjectRequests extends ListRecords
{
    protected static string $resource = DataSubjectRequestResource::class;
}
