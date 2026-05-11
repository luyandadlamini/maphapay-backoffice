<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\CardAuditLogResource\Pages;

use App\Filament\Admin\Resources\Cards\CardAuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListCardAuditLogs extends ListRecords
{
    protected static string $resource = CardAuditLogResource::class;
}
