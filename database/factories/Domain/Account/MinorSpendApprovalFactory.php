<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Account;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorSpendApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

class MinorSpendApprovalFactory extends Factory
{
    protected $model = MinorSpendApproval::class;

    public function definition(): array
    {
        return [
            'minor_account_uuid'    => Account::factory(),
            'guardian_account_uuid' => Account::factory(),
            'from_account_uuid'     => Account::factory(),
            'to_account_uuid'       => Account::factory(),
            'amount'                => $this->faker->randomFloat(2, 1, 500),
            'asset_code'            => 'USD',
            'merchant_category'     => $this->faker->randomElement(['groceries', 'entertainment', 'retail']),
            'status'                => 'pending',
            'expires_at'            => now()->addDay(),
            'decided_at'            => null,
        ];
    }
}
