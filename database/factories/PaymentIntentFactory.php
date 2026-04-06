<?php

namespace Database\Factories;

use App\Domain\MobilePayment\Models\PaymentIntent;
use App\Domain\MobilePayment\Enums\PaymentIntentStatus;
use App\Domain\Commerce\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentIntentFactory extends Factory
{
    protected $model = PaymentIntent::class;

    public function definition(): array
    {
        return [
            'public_id' => $this->faker->uuid(),
            'user_id' => User::factory(),
            'merchant_id' => Merchant::factory(),
            'amount' => '10000',
            'asset' => 'SZL',
            'network' => 'MTN',
            'status' => PaymentIntentStatus::CREATED,
            'expires_at' => now()->addHour(),
        ];
    }
}
