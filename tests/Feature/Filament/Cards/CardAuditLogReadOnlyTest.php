<?php

declare(strict_types=1);

use App\Domain\CardSubscriptions\Models\CardAuditLog;
use App\Filament\Admin\Resources\Cards\CardAuditLogResource;

it('disallows editing card audit log records in Filament', function (): void {
    $log = new CardAuditLog();

    expect(CardAuditLogResource::canEdit($log))->toBeFalse();
    expect(CardAuditLogResource::canDelete($log))->toBeFalse();
    expect(CardAuditLogResource::canDeleteAny())->toBeFalse();
});
