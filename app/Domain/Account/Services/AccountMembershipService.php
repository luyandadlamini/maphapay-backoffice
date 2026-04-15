<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class AccountMembershipService
{
    public function createOwnerMembership(User $user, string $tenantId, Account $account): AccountMembership
    {
        $membership = AccountMembership::query()->updateOrCreate(
            [
                'user_uuid' => $user->uuid,
                'tenant_id' => $tenantId,
                'account_uuid' => $account->uuid,
            ],
            [
                'account_type' => (string) ($account->type ?? 'personal'),
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => now(),
            ],
        );

        return $membership->refresh();
    }

    public function userHasAccessToAccount(User $user, string $accountUuid): bool
    {
        return AccountMembership::query()
            ->forUser($user->uuid)
            ->forAccount($accountUuid)
            ->active()
            ->exists();
    }

    /**
     * @return Collection<int, AccountMembership>
     */
    public function getActiveMemberships(User $user): Collection
    {
        return AccountMembership::query()
            ->forUser($user->uuid)
            ->active()
            ->orderByDesc('joined_at')
            ->orderBy('created_at')
            ->get();
    }

    public function getMembershipForAccount(User $user, string $accountUuid): ?AccountMembership
    {
        return AccountMembership::query()
            ->forUser($user->uuid)
            ->forAccount($accountUuid)
            ->active()
            ->first();
    }
}
