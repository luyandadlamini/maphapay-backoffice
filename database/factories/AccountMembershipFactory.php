<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domain\Account\Models\AccountMembership>
 */
class AccountMembershipFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = AccountMembership::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'minor_account_id' => Account::factory(),
            'guardian_account_id' => Account::factory(),
            'role' => 'guardian',
            'permissions' => null,
        ];
    }

    /**
     * Set the membership role to 'guardian' (primary guardian).
     */
    public function asGuardian(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'guardian',
        ]);
    }

    /**
     * Set the membership role to 'co_guardian' (secondary guardian).
     */
    public function asCoGuardian(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'co_guardian',
        ]);
    }

    /**
     * Set custom permissions.
     */
    public function withPermissions(?array $permissions): static
    {
        return $this->state(fn (array $attributes) => [
            'permissions' => $permissions,
        ]);
    }

    /**
     * Set default permissions for a guardian.
     */
    public function withGuardianPermissions(): static
    {
        return $this->state(fn (array $attributes) => [
            'permissions' => [
                'canApproveSpending' => true,
                'canManageChores' => true,
                'canViewChildAccounts' => true,
                'canTopUpBalance' => true,
                'canDeleteAccount' => true,
                'canSetLimits' => true,
            ],
        ]);
    }

    /**
     * Set default permissions for a co-guardian.
     */
    public function withCoGuardianPermissions(): static
    {
        return $this->state(fn (array $attributes) => [
            'permissions' => [
                'canApproveSpending' => true,
                'canManageChores' => true,
                'canViewChildAccounts' => true,
                'canTopUpBalance' => true,
            ],
        ]);
    }
}
