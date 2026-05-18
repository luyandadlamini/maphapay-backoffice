<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domain\Account\Models\Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * Opt-in: seed a central AccountMembership row pointing at this account,
     * mirroring the production invariant where every Account has a paired
     * owner membership on the central directory. Required for cross-tenant
     * lookups (e.g. send-money recipient resolution).
     *
     * Not the default because seeding a membership activates the production
     * tenancy-initialization path in ResolveAccountContext, and tests that
     * pre-date the central directory still rely on tenancy staying dormant.
     */
    public function withMembership(): static
    {
        return $this->afterCreating(function (Account $account): void {
            if (AccountMembership::query()->where('account_uuid', $account->uuid)->exists()) {
                return;
            }

            $type = (string) ($account->type ?? 'personal');
            $tenantId = self::ensureFactoryTenant();

            AccountMembership::query()->create([
                'id'           => (string) Str::uuid(),
                'user_uuid'    => $account->user_uuid,
                'tenant_id'    => $tenantId,
                'account_uuid' => $account->uuid,
                'account_type' => $type === 'standard' ? 'personal' : $type,
                'role'         => 'owner',
                'status'       => 'active',
                'joined_at'    => now(),
            ]);
        });
    }

    /**
     * Insert a sentinel tenant row directly on the central connection,
     * bypassing Stancl's CreateDatabase job (which is heavy and brittle in tests).
     */
    private static function ensureFactoryTenant(): string
    {
        $tenantId = '00000000-0000-0000-0000-00000000fac7';
        $central = DB::connection('central');

        if (! $central->table('tenants')->where('id', $tenantId)->exists()) {
            $central->table('tenants')->insert([
                'id'         => $tenantId,
                'name'       => 'AccountFactory sentinel tenant',
                'plan'       => 'default',
                'team_id'    => null,
                'created_at' => now(),
                'updated_at' => now(),
                'data'       => json_encode([]),
            ]);
        }

        return $tenantId;
    }

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'uuid'      => $this->faker->uuid(),
            'name'      => $this->faker->words(3, true),
            'user_uuid' => static fn (): string => User::factory()->create()->uuid,
            'type'      => 'personal',
            'balance'   => $this->faker->numberBetween(0, 100000),
            'frozen'    => false,
        ];
    }

    /**
     * Configure the model factory to create an account with zero balance.
     *
     * @return Factory
     */
    public function zeroBalance(): Factory
    {
        return $this->afterCreating(function (Account $account) {
            // Use updateOrCreate to handle existing AccountBalance records
            AccountBalance::updateOrCreate(
                [
                    'account_uuid' => $account->uuid,
                    'asset_code'   => 'USD',
                ],
                [
                    'balance' => 0,
                ]
            );
        })->state(function (array $attributes) {
            return [
                'balance' => 0,
            ];
        });
    }

    /**
     * Configure the model factory to create an account with a specific balance.
     *
     * @param int $balance
     * @return Factory
     */
    public function withBalance(int $balance): Factory
    {
        return $this->afterCreating(function (Account $account) use ($balance) {
            // Use updateOrCreate to handle existing AccountBalance records
            AccountBalance::updateOrCreate(
                [
                    'account_uuid' => $account->uuid,
                    'asset_code'   => 'USD',
                ],
                [
                    'balance' => $balance,
                ]
            );
        })->state(function (array $attributes) use ($balance) {
            return [
                'balance' => $balance,
            ];
        });
    }

    /**
     * Configure the model factory for a specific user.
     *
     * @param User $user
     * @return Factory
     */
    public function forUser(User $user): Factory
    {
        return $this->state(function (array $attributes) use ($user) {
            return [
                'user_uuid' => $user->uuid,
            ];
        });
    }
}
