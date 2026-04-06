<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\User\Values\UserRoles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesSeeder extends Seeder
{
    public function run()
    {
        $roles = [
            UserRoles::BUSINESS->value,
            UserRoles::PRIVATE->value,
            UserRoles::ADMIN->value,
            // Note: super-admin (hyphen) is managed by RolesAndPermissionsSeeder — do not duplicate here
        ];

        // Seed each role into the database
        foreach ($roles as $role) {
            // Create for both web and sanctum guards
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'sanctum']);
        }
    }
}
