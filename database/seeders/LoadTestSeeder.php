<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Models\User;
use Illuminate\Database\Seeder;

class LoadTestSeeder extends Seeder
{
    /**
     * Run the database seeds for load testing.
     */
    public function run(): void
    {
        // Ensure assets exist
        if (Asset::count() === 0) {
            $this->call(AssetSeeder::class);
        }

        // Create test users with accounts for load testing
        $this->command->info('Creating test users and accounts for load testing...');

        // Create 10 test users with multiple accounts each
        for ($i = 1; $i <= 10; $i++) {
            $user = User::factory()->create([
                'email' => "loadtest{$i}@example.com",
                'name'  => "Load Test User {$i}",
            ]);

            // Create 3 accounts per user with initial balances
            for ($j = 1; $j <= 3; $j++) {
                $account = Account::factory()->forUser($user)->create([
                    'name' => "Load Test Account {$i}-{$j}",
                ]);

                // Add balances in different currencies
                $account->addBalance('USD', 100000); // $1,000
                $account->addBalance('EUR', 50000);  // €500
                $account->addBalance('GBP', 30000);  // £300
            }
        }

        $this->command->info('Load test data seeded successfully!');
    }
}
