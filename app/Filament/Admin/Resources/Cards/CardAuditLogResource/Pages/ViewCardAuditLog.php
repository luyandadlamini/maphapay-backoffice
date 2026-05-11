<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\CardAuditLogResource\Pages;

use App\Filament\Admin\Resources\Cards\CardAuditLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCardAuditLog extends ViewRecord
{
    protected static string $resource = CardAuditLogResource::class;
}
