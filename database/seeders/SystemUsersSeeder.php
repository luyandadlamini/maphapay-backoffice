<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SystemUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->createSystemUser(
            'system@maphapay.com',
            'Maphapay System',
            'system'
        );

        $this->createSystemUser(
            'suspense@maphapay.com',
            'Suspense Account',
            'suspense'
        );

        $this->createSystemUser(
            'treasury@maphapay.com',
            'Treasury Account',
            'treasury'
        );

        $this->createSystemUser(
            'pool@maphapay.com',
            'Liquidity Pool System',
            'pool'
        );
    }

    private function createSystemUser(string $email, string $name, string $type): void
    {
        $existingUuid = match ($type) {
            'system' => config('system_users.uuid.system'),
            'suspense' => config('system_users.uuid.suspense'),
            'treasury' => config('system_users.uuid.treasury'),
            'pool' => config('system_users.uuid.pool'),
            default => null,
        };

        $userData = [
            'name' => $name,
            'password' => Hash::make(Str::uuid()->toString()),
            'email' => $email,
        ];

        if ($existingUuid) {
            $userData['uuid'] = $existingUuid;
        }

        User::firstOrCreate(
            ['email' => $email],
            $userData
        );

        $this->command->info("System user created/found: {$email}");
    }
}
