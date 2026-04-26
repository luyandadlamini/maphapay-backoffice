<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use App\Models\User;
use App\Support\Backoffice\BackofficeWorkspaceAccess;

/**
 * Defence-in-depth: treasury / aggregate widgets only for finance or platform administration workspaces.
 */
trait VisibleOnlyOnFinanceAdminSurface
{
    public static function userMayViewFinanceAdminSurface(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $access = app(BackofficeWorkspaceAccess::class);

        return $access->canAccess('finance', $user)
            || $access->canAccess('platform_administration', $user);
    }
}
