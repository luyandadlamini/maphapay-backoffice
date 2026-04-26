<?php

declare(strict_types=1);

namespace App\Filament\Admin\Traits;

use App\Filament\Admin\Support\LegacyAdminNavigation;

/**
 * Hides Filament resources whose navigation group is not in brand.admin_modules.
 *
 * When ADMIN_MODULES is not set (null), all groups are visible (FinAegis full platform).
 * When set to a comma-separated list, only listed groups appear (e.g. Zelta mobile wallet).
 *
 * Resources in {@see LegacyAdminNavigation::NAVIGATION_GROUP} are additionally gated by
 * `maphapay.show_legacy_admin_nav` unless the user is super-admin.
 *
 * Usage: add `use RespectsModuleVisibility;` to any Filament Resource class.
 */
trait RespectsModuleVisibility
{
    public static function shouldRegisterNavigation(): bool
    {
        if (static::getNavigationGroup() === LegacyAdminNavigation::NAVIGATION_GROUP) {
            $user = auth()->user();
            if ($user !== null && $user->hasRole('super-admin')) {
                return true;
            }

            if (! (bool) config('maphapay.show_legacy_admin_nav', false)) {
                return false;
            }

            // Legacy flag on: still respect ADMIN_MODULES for non–super-admin users.
        }

        /** @var array<string>|null $allowedModules */
        $allowedModules = config('brand.admin_modules');

        // null = show all (FinAegis full platform mode)
        if ($allowedModules === null) {
            return true;
        }

        $group = static::getNavigationGroup();

        // When ADMIN_MODULES is set, ungrouped resources are hidden
        if ($group === null || $group === '') {
            return false;
        }

        return in_array($group, $allowedModules, true);
    }
}
