<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CardIssuance\Models;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Models\Cardholder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    protected $model = Card::class;

    public function definition(): array
    {
        return [
            'user_id'           => User::factory(),
            'cardholder_id'     => Cardholder::factory(),
            'issuer_card_token' => Str::uuid()->toString(),
            'issuer'            => 'demo',
            'last4'             => (string) $this->faker->numberBetween(1000, 9999),
            'network'           => $this->faker->randomElement(['visa', 'mastercard']),
            'status'            => 'active',
            'currency'          => 'USD',
        ];
    }
}
