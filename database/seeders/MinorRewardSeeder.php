<?php
declare(strict_types=1);
namespace Database\Seeders;

use App\Domain\Account\Models\MinorReward;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MinorRewardSeeder extends Seeder
{
    public function run(): void
    {
        $rewards = [
            [
                'id'          => Str::uuid(),
                'name'        => 'MTN Airtime 50 SZL',
                'description' => 'Redeem points for 50 SZL MTN airtime credited to your number.',
                'points_cost' => 100,
                'type'        => 'airtime',
                'metadata'    => json_encode(['amount' => '50.00', 'provider' => 'MTN', 'asset_code' => 'SZL']),
                'stock'       => -1,
                'is_active'   => true,
                'min_permission_level' => 3,
            ],
            [
                'id'          => Str::uuid(),
                'name'        => 'MTN 1GB Data Bundle',
                'description' => 'Redeem points for 1GB MTN data bundle.',
                'points_cost' => 150,
                'type'        => 'data_bundle',
                'metadata'    => json_encode(['data_gb' => 1, 'provider' => 'MTN']),
                'stock'       => -1,
                'is_active'   => true,
                'min_permission_level' => 3,
            ],
            [
                'id'          => Str::uuid(),
                'name'        => 'Grocery Voucher 100 SZL',
                'description' => 'Redeem points for a 100 SZL grocery store voucher.',
                'points_cost' => 200,
                'type'        => 'voucher',
                'metadata'    => json_encode(['amount' => '100.00', 'merchant' => 'Shoprite', 'asset_code' => 'SZL']),
                'stock'       => 50,
                'is_active'   => true,
                'min_permission_level' => 3,
            ],
            [
                'id'          => Str::uuid(),
                'name'        => 'UNICEF Donation',
                'description' => 'Donate 50 points to UNICEF Eswatini on behalf of your account.',
                'points_cost' => 50,
                'type'        => 'charity_donation',
                'metadata'    => json_encode(['charity' => 'UNICEF Eswatini']),
                'stock'       => -1,
                'is_active'   => true,
                'min_permission_level' => 1,
            ],
        ];

        foreach ($rewards as $reward) {
            MinorReward::firstOrCreate(['id' => $reward['id']], $reward);
        }
    }
}
