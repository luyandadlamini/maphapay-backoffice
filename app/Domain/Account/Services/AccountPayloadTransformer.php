<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use Illuminate\Support\Collection;

class AccountPayloadTransformer
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function transformUserMemberships(User $user, bool $includeStatus = false): array
    {
        $memberships = $user->activeAccountMemberships()
            ->with('user')
            ->get();

        return $this->transformMemberships($memberships, $user, $includeStatus);
    }

    /**
     * @param Collection<int, AccountMembership> $memberships
     * @return array<int, array<string, mixed>>
     */
    public function transformMemberships(Collection $memberships, ?User $currentUser = null, bool $includeStatus = false): array
    {
        return $memberships
            ->map(function (AccountMembership $membership) use ($currentUser, $includeStatus): array {
                $account = Account::query()->where('uuid', $membership->account_uuid)->first();

                $payload = [
                    'account_uuid' => $membership->account_uuid,
                    'tenant_id' => $membership->tenant_id,
                    'account_type' => $membership->account_type,
                    'display_name' => $this->resolveDisplayName($membership, $currentUser, $account),
                    'role' => $membership->role,
                    'capabilities' => $membership->capabilities ?? [],
                    'verification_tier' => $membership->verification_tier ?? 'unverified',
                    'balance_preview' => null,
                    'currency' => 'SZL',
                ];

                if ($includeStatus) {
                    $payload['status'] = $membership->status;
                }

                if (($account?->type ?? null) === 'minor') {
                    $payload['account_tier'] = $account?->tier;
                    $payload['permission_level'] = $account?->permission_level;
                    $payload['parent_account_uuid'] = $account?->parent_account_id;
                }

                return $payload;
            })
            ->values()
            ->all();
    }

    private function resolveDisplayName(AccountMembership $membership, ?User $currentUser, ?Account $minorAccount): string
    {
        if ($membership->account_type === 'personal') {
            return $membership->display_name
                ?: $currentUser?->name
                ?: $membership->user?->name
                ?: 'Personal';
        }

        if ($membership->display_name) {
            return $membership->display_name;
        }

        if ($minorAccount instanceof Account) {
            return $minorAccount->name ?: $membership->account_uuid;
        }

        return $membership->account_uuid;
    }
}
