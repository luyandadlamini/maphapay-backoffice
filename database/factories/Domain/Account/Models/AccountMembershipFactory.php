<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Account\Models;

use App\Domain\Account\Models\AccountMembership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountMembership>
 */
class AccountMembershipFactory extends Factory
{
    protected $model = AccountMembership::class;

    public function definition(): array
    {
        return [
            'id'           => (string) Str::uuid(),
            'user_uuid'    => User::factory(),
            'tenant_id'    => Tenant::factory(),
            'account_uuid' => (string) Str::uuid(),
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
            'joined_at'    => now(),
        ];
    }

    /**
     * When user_uuid is overridden with a raw UUID string (not a factory),
     * ensure a matching user row exists before the FK-checked insert fires.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (AccountMembership $membership): void {
            if (! User::where('uuid', $membership->user_uuid)->exists()) {
                User::factory()->create(['uuid' => $membership->user_uuid]);
            }
        });
    }
}
