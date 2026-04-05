<?php

namespace Database\Factories;

use App\Domain\Support\Models\SupportCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportCase>
 */
class SupportCaseFactory extends Factory
{
    protected $model = SupportCase::class;
    
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'     => \App\Models\User::factory(),
            'assigned_to' => null,
            'subject'     => fake()->sentence(),
            'description' => fake()->paragraph(),
            'status'      => fake()->randomElement(['open', 'in_progress', 'resolved']),
            'priority'    => fake()->randomElement(['low', 'medium', 'high', 'urgent']),
        ];
    }
}
