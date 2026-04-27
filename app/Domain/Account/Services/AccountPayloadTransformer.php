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
            ->orderByRaw("case when account_type = 'personal' then 0 else 1 end")
            ->orderByDesc('joined_at')
            ->get();

        $payloads = $this->transformMemberships($memberships, $user, $includeStatus);

        return $this->appendOwnedMinorChildPayloads($payloads, $user, $includeStatus);
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
                    'account_uuid'      => $membership->account_uuid,
                    'tenant_id'         => $membership->tenant_id,
                    'account_type'      => $membership->account_type,
                    'display_name'      => $this->resolveDisplayName($membership, $currentUser, $account),
                    'role'              => $membership->role,
                    'capabilities'      => $membership->capabilities ?? [],
                    'verification_tier' => $membership->verification_tier ?? 'unverified',
                    'balance_preview'   => null,
                    'currency'          => 'SZL',
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

    public function resolveActiveAccountUuid(User $user): ?string
    {
        $accounts = $this->transformUserMemberships($user);

        $activeAccountUuid = $accounts[0]['account_uuid'] ?? null;

        return is_string($activeAccountUuid) && $activeAccountUuid !== ''
            ? $activeAccountUuid
            : null;
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

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<int, array<string, mixed>>
     */
    private function appendOwnedMinorChildPayloads(array $payloads, User $user, bool $includeStatus): array
    {
        $existingAccountUuids = collect($payloads)
            ->pluck('account_uuid')
            ->filter(fn (mixed $uuid): bool => is_string($uuid) && $uuid !== '')
            ->all();

        try {
            $childAccounts = Account::query()
                ->where('user_uuid', $user->uuid)
                ->where('type', 'minor')
                ->whereNotIn('uuid', $existingAccountUuids)
                ->orderBy('id')
                ->get();
        } catch (\Illuminate\Database\QueryException $e) {
            // The accounts table is missing columns (tenant schema behind migrations).
            // Log a warning and return what we have so login is not blocked.
            \Illuminate\Support\Facades\Log::warning('AccountPayloadTransformer: accounts schema behind migrations, skipping minor child lookup', [
                'user_uuid' => $user->uuid,
                'error'     => $e->getMessage(),
            ]);

            return array_values($payloads);
        }

        foreach ($childAccounts as $account) {
            $guardianMembership = AccountMembership::query()
                ->forAccount($account->uuid)
                ->active()
                ->whereIn('role', ['guardian', 'co_guardian'])
                ->orderByRaw("case when role = 'guardian' then 0 else 1 end")
                ->orderBy('joined_at')
                ->first();

            if ($guardianMembership === null) {
                continue;
            }

            $payload = [
                'account_uuid'        => $account->uuid,
                'tenant_id'           => $guardianMembership->tenant_id,
                'account_type'        => $account->type,
                'display_name'        => $account->name ?: $account->uuid,
                'role'                => 'child',
                'capabilities'        => [],
                'verification_tier'   => 'unverified',
                'balance_preview'     => null,
                'currency'            => 'SZL',
                'account_tier'        => $account->tier,
                'permission_level'    => $account->permission_level,
                'parent_account_uuid' => $account->parent_account_id,
            ];

            if ($includeStatus) {
                $payload['status'] = 'active';
            }

            $payloads[] = $payload;
        }

        return array_values($payloads);
    }
}
