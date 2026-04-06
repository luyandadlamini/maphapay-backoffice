<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Account\Models\TransactionProjection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domain\Account\Models\TransactionProjection>
 */
class TransactionProjectionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = TransactionProjection::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid'                   => Str::uuid(),
            'account_uuid'           => Str::uuid(),
            'asset_code'             => 'USD',
            'amount'                 => $this->faker->numberBetween(100, 100000),
            'type'                   => $this->faker->randomElement(['deposit', 'withdrawal', 'transfer']),
            'description'            => $this->faker->sentence(),
            'reference'              => 'REF-' . strtoupper($this->faker->lexify('??????')),
            'hash'                   => hash('sha512', Str::random(64)),
            'metadata'               => [],
            'status'                 => 'completed',
            'related_account_uuid'   => null,
            'transaction_group_uuid' => null,
        ];
    }

    /**
     * Indicate that the transaction is a deposit.
     */
    public function deposit(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'deposit',
        ]);
    }

    /**
     * Indicate that the transaction is a withdrawal.
     */
    public function withdrawal(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'withdrawal',
        ]);
    }

    /**
     * Indicate that the transaction is a transfer.
     */
    public function transfer(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'transfer',
        ]);
    }
}
