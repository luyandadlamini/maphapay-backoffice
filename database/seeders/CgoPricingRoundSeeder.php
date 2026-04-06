<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Cgo\Models\CgoPricingRound;
use Illuminate\Database\Seeder;

class CgoPricingRoundSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create first round (pre-launch)
        CgoPricingRound::create([
            'round_number'         => 1,
            'share_price'          => 10.00, // $10 per share
            'max_shares_available' => 10000, // 10,000 shares = 1% of total
            'shares_sold'          => 0,
            'total_raised'         => 0,
            'started_at'           => now(),
            'is_active'            => true,
        ]);
    }
}
