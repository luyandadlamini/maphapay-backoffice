<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SupportCaseResource\Pages;

use App\Filament\Admin\Resources\SupportCaseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupportCase extends CreateRecord
{
    protected static string $resource = SupportCaseResource::class;
}
