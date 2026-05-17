<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Account\Models\AccountMembership;
use App\Models\Tenant;
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
            'user_uuid'    => (string) Str::uuid(),
            'tenant_id'    => Tenant::factory(),
            'account_uuid' => (string) Str::uuid(),
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
            'joined_at'    => now(),
        ];
    }
}
