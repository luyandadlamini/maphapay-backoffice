<?php

declare(strict_types=1);

namespace Database\Factories\Domain\CardIssuance\Models;

use App\Domain\CardIssuance\Models\Cardholder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cardholder>
 */
class CardholderFactory extends Factory
{
    protected $model = Cardholder::class;

    public function definition(): array
    {
        return [
            'user_id'    => User::factory(),
            'first_name' => $this->faker->firstName(),
            'last_name'  => $this->faker->lastName(),
            'email'      => $this->faker->safeEmail(),
            'kyc_status' => 'pending',
        ];
    }
}
