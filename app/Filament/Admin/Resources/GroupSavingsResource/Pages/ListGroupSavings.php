<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GroupSavingsResource\Pages;

use App\Filament\Admin\Resources\GroupSavingsResource;
use Filament\Resources\Pages\ListRecords;

class ListGroupSavings extends ListRecords
{
    protected static string $resource = GroupSavingsResource::class;
}
