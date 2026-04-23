<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MinorAccountLifecycleTransitionResource\Pages;

use App\Filament\Admin\Resources\MinorAccountLifecycleTransitionResource;
use Filament\Resources\Pages\ListRecords;

class ListMinorAccountLifecycleTransitions extends ListRecords
{
    protected static string $resource = MinorAccountLifecycleTransitionResource::class;
}
