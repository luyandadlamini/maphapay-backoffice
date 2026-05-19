<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\UserResource\RelationManagers\Concerns;

use App\Domain\Account\Models\AccountMembership;
use Illuminate\Database\Eloquent\Model;

trait RequiresActiveAccountMembership
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! AccountMembership::query()
            ->where('user_uuid', (string) $ownerRecord->getAttribute('uuid'))
            ->where('status', 'active')
            ->exists()) {
            return false;
        }

        return parent::canViewForRecord($ownerRecord, $pageClass);
    }
}
