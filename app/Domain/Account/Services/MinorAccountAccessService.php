<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class MinorAccountAccessService
{
    /**
     * @throws AuthorizationException
     */
    public function authorizeView(User $user, Account $minorAccount): void
    {
        if (! $this->canView($user, $minorAccount)) {
            throw new AuthorizationException('Forbidden. Only the child, guardian, or co-guardian may access this resource.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function authorizeGuardian(User $user, Account $minorAccount, ?string $actingAccountUuid = null): Account
    {
        if (! $this->hasGuardianAccess($user, $minorAccount)) {
            throw new AuthorizationException('Forbidden. Only a guardian or co-guardian may perform this action.');
        }

        $actingAccount = $this->resolveActingAccount($user, $minorAccount, $actingAccountUuid);

        if ($actingAccount === null) {
            throw new AuthorizationException('Forbidden. Guardian access requires a valid owned account context.');
        }

        return $actingAccount;
    }

    public function canView(User $user, Account $minorAccount): bool
    {
        return $this->isChild($user, $minorAccount) || $this->hasGuardianAccess($user, $minorAccount);
    }

    public function hasGuardianAccess(User $user, Account $minorAccount): bool
    {
        if ($minorAccount->type !== 'minor') {
            return false;
        }

        return AccountMembership::query()
            ->forAccount($minorAccount->uuid)
            ->forUser($user->uuid)
            ->active()
            ->whereIn('role', ['guardian', 'co_guardian'])
            ->exists();
    }

    public function isChild(User $user, Account $minorAccount): bool
    {
        if ($minorAccount->type !== 'minor' || $minorAccount->user_uuid !== $user->uuid) {
            return false;
        }

        return AccountMembership::query()
            ->forAccount($minorAccount->uuid)
            ->active()
            ->whereIn('role', ['guardian', 'co_guardian'])
            ->exists();
    }

    private function resolveActingAccount(User $user, Account $minorAccount, ?string $actingAccountUuid): ?Account
    {
        if (is_string($actingAccountUuid) && $actingAccountUuid !== '' && $actingAccountUuid !== $minorAccount->uuid) {
            $contextAccount = Account::query()
                ->where('uuid', $actingAccountUuid)
                ->where('user_uuid', $user->uuid)
                ->first();

            if ($contextAccount !== null) {
                return $contextAccount;
            }
        }

        return Account::query()
            ->where('user_uuid', $user->uuid)
            ->where('uuid', '!=', $minorAccount->uuid)
            ->orderByRaw("case when type = 'personal' then 0 else 1 end")
            ->orderBy('id')
            ->first();
    }
}
