<?php

declare(strict_types=1);

namespace App\Domain\User\Values;

enum UserRoles: string
{
    // End-user roles
    case BUSINESS = 'business';
    case PRIVATE = 'private';

    // Administrative roles
    case ADMIN = 'admin';
    case SUPER_ADMIN = 'super-admin';
    case OPERATIONS_L2 = 'operations-l2';
    case FINANCE_LEAD = 'finance-lead';
    case COMPLIANCE_MANAGER = 'compliance-manager';
    case SUPPORT_L1 = 'support-l1';

    /**
     * Returns all roles that may access the Filament admin panel.
     *
     * @return array<string>
     */
    public static function adminPanelRoles(): array
    {
        return [
            self::ADMIN->value,
            self::SUPER_ADMIN->value,
            self::OPERATIONS_L2->value,
            self::FINANCE_LEAD->value,
            self::COMPLIANCE_MANAGER->value,
            self::SUPPORT_L1->value,
        ];
    }
}
