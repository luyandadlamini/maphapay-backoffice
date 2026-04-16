<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;

class AccountPolicy
{
    /**
     * Allow a user to view a minor account.
     *
     * Returns true if:
     * - User is the child (authenticated user uuid === account.user_uuid AND account.type === 'minor')
     * - OR user is a guardian or co_guardian in AccountMembership table for this account
     */
    public function viewMinor(User $user, Account $account): bool
    {
        // Child can view their own account
        if ($user->uuid === $account->user_uuid && $account->account_type === 'minor') {
            return true;
        }

        // Guardian or co-guardian can view the minor account
        $membership = AccountMembership::forMinorAccount($account->uuid)
            ->where('guardian_account_id', function ($query) use ($user) {
                $query->selectRaw('uuid')
                    ->from('accounts')
                    ->where('user_uuid', $user->uuid);
            })
            ->first();

        return $membership !== null;
    }

    /**
     * Allow a user to view any minor account (that they manage).
     *
     * Returns true if user has any role in AccountMembership (is a guardian of any child).
     */
    public function viewAnyMinor(User $user): bool
    {
        // Find any guardian accounts belonging to this user
        $userAccountUuids = Account::where('user_uuid', $user->uuid)
            ->pluck('uuid');

        if ($userAccountUuids->isEmpty()) {
            return false;
        }

        // Check if this user is a guardian or co-guardian of any minor account
        return AccountMembership::whereIn('guardian_account_id', $userAccountUuids)
            ->exists();
    }

    /**
     * Allow a user to create a minor account.
     *
     * Returns true if:
     * - User is authenticated
     * - User has a personal account (type === 'personal')
     */
    public function createMinor(User $user): bool
    {
        // User must have a personal account
        return Account::where('user_uuid', $user->uuid)
            ->where('account_type', 'personal')
            ->exists();
    }

    /**
     * Allow a user to update a minor account.
     *
     * Only primary guardians (role='guardian') can update minor accounts.
     * Co-guardians can approve transactions but cannot change account settings.
     */
    public function updateMinor(User $user, Account $account): bool
    {
        // Only primary guardians can update
        return AccountMembership::forMinorAccount($account->uuid)
            ->where('guardian_account_id', function ($query) use ($user) {
                $query->selectRaw('uuid')
                    ->from('accounts')
                    ->where('user_uuid', $user->uuid);
            })
            ->where('role', 'guardian')
            ->exists();
    }

    /**
     * Allow a user to delete a minor account.
     *
     * Only primary guardians (role='guardian') can delete minor accounts.
     * Co-guardians cannot delete accounts.
     */
    public function deleteMinor(User $user, Account $account): bool
    {
        // Only primary guardians can delete
        return AccountMembership::forMinorAccount($account->uuid)
            ->where('guardian_account_id', function ($query) use ($user) {
                $query->selectRaw('uuid')
                    ->from('accounts')
                    ->where('user_uuid', $user->uuid);
            })
            ->where('role', 'guardian')
            ->exists();
    }

    /**
     * Allow a user to manage children (create/view/manage child accounts).
     *
     * Returns true if:
     * - User is authenticated
     * - User has a personal account
     */
    public function manageChildren(User $user): bool
    {
        return Account::where('user_uuid', $user->uuid)
            ->where('account_type', 'personal')
            ->exists();
    }
}
