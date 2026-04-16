<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AccountPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view a minor account.
     *
     * Returns true if:
     * - User is the child: account.user_uuid === $user->uuid AND account.account_type === 'minor'
     * - OR user has active AccountMembership for this account with role IN ('guardian', 'co_guardian')
     */
    public function viewMinor(User $user, Account $account): bool
    {
        // Check if user is the child (owner of the minor account)
        if ($account->user_uuid === $user->uuid && $account->account_type === 'minor') {
            return true;
        }

        // Check if user has active membership as guardian or co_guardian
        return AccountMembership::query()
            ->forAccount($account->uuid)
            ->forUser($user->uuid)
            ->active()
            ->whereIn('role', ['guardian', 'co_guardian'])
            ->exists();
    }

    /**
     * Determine whether the user can view any minor accounts.
     *
     * Returns true if user has ANY active AccountMembership with role = 'guardian' or 'co_guardian'
     */
    public function viewAnyMinor(User $user): bool
    {
        return AccountMembership::query()
            ->forUser($user->uuid)
            ->active()
            ->whereIn('role', ['guardian', 'co_guardian'])
            ->exists();
    }

    /**
     * Determine whether the user can create a minor account.
     *
     * Returns true if user has at least one AccountMembership with role = 'owner' on a personal account
     */
    public function createMinor(User $user): bool
    {
        return AccountMembership::query()
            ->forUser($user->uuid)
            ->active()
            ->where('role', 'owner')
            ->where('account_type', 'personal')
            ->exists();
    }

    /**
     * Determine whether the user can update a minor account.
     *
     * Only primary guardians (role='guardian') can update.
     * Returns true if user has active AccountMembership with role = 'guardian' for this account.
     */
    public function updateMinor(User $user, Account $account): bool
    {
        return AccountMembership::query()
            ->forAccount($account->uuid)
            ->forUser($user->uuid)
            ->active()
            ->where('role', 'guardian')
            ->exists();
    }

    /**
     * Determine whether the user can delete a minor account.
     *
     * Only primary guardians can delete.
     * Returns true if user has active AccountMembership with role = 'guardian' for this account.
     */
    public function deleteMinor(User $user, Account $account): bool
    {
        return AccountMembership::query()
            ->forAccount($account->uuid)
            ->forUser($user->uuid)
            ->active()
            ->where('role', 'guardian')
            ->exists();
    }

    /**
     * Determine whether the user can manage children.
     *
     * Returns true if user has any AccountMembership with role = 'owner' on a personal account
     */
    public function manageChildren(User $user): bool
    {
        return AccountMembership::query()
            ->forUser($user->uuid)
            ->active()
            ->where('role', 'owner')
            ->where('account_type', 'personal')
            ->exists();
    }
}
