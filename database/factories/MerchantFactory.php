<?php

namespace Database\Factories;

use App\Domain\Commerce\Models\Merchant;
use App\Domain\Commerce\Enums\MerchantStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    public function definition(): array
    {
        return [
            'public_id'         => 'merchant_' . bin2hex(random_bytes(16)),
            'display_name'      => $this->faker->company(),
            'status'            => 'active',
            'accepted_assets'   => json_encode(['USDC']),
            'accepted_networks' => json_encode(['POLYGON']),
        ];
    }
}
