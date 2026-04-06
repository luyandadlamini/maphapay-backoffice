<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Custodian\Models;

use App\Domain\Account\Models\Account;
use App\Domain\Custodian\Models\CustodianAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustodianAccount>
 */
class CustodianAccountFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = CustodianAccount::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid'                   => (string) Str::uuid(),
            'account_uuid'           => Account::factory(),
            'custodian_name'         => $this->faker->randomElement(['anchorage', 'circle', 'fireblocks', 'coinbase']),
            'custodian_account_id'   => $this->faker->uuid(),
            'custodian_account_name' => $this->faker->words(3, true),
            'status'                 => 'active',
            'is_primary'             => false,
            'metadata'               => [
                'created_via'  => 'factory',
                'test_account' => true,
            ],
            'last_known_balance' => $this->faker->numberBetween(0, 1000000),
            'last_synced_at'     => now(),
        ];
    }

    /**
     * Indicate that the custodian account is primary.
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }

    /**
     * Indicate that the custodian account is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the custodian account is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }

    /**
     * Set a specific custodian.
     */
    public function forCustodian(string $custodianName): static
    {
        return $this->state(fn (array $attributes) => [
            'custodian_name' => $custodianName,
        ]);
    }

    /**
     * Set a specific account.
     */
    public function forAccount(Account|string $account): static
    {
        $accountUuid = $account instanceof Account ? $account->uuid : $account;

        return $this->state(fn (array $attributes) => [
            'account_uuid' => $accountUuid,
        ]);
    }

    /**
     * Set the last known balance.
     */
    public function withBalance(int $balance): static
    {
        return $this->state(fn (array $attributes) => [
            'last_known_balance' => $balance,
        ]);
    }
}
