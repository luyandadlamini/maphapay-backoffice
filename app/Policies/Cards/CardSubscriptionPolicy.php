<?php

declare(strict_types=1);

namespace App\Policies\Cards;

use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Role names match {@see \Database\Seeders\RolesAndPermissionsSeeder} (hyphenated Spatie roles).
 */
class CardSubscriptionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['support-l1', 'fraud-analyst', 'compliance-manager', 'operations-l2', 'super-admin']);
    }

    public function view(User $user, CardSubscription $subscription): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CardSubscription $subscription): bool
    {
        return $user->hasAnyRole(['operations-l2', 'compliance-manager', 'super-admin']);
    }

    public function delete(User $user, CardSubscription $subscription): bool
    {
        return false;
    }

    public function forceDelete(User $user, CardSubscription $subscription): bool
    {
        return false;
    }

    public function restore(User $user, CardSubscription $subscription): bool
    {
        return false;
    }

    public function suspend(User $user, CardSubscription $subscription): bool
    {
        return $user->hasAnyRole(['fraud-analyst', 'operations-l2', 'compliance-manager', 'super-admin']);
    }

    public function forceCancel(User $user, CardSubscription $subscription): bool
    {
        return $user->hasAnyRole(['operations-l2', 'compliance-manager', 'super-admin']);
    }

    public function waive(User $user, CardSubscription $subscription): bool
    {
        return $user->hasAnyRole(['operations-l2', 'compliance-manager', 'super-admin']);
    }

    public function changePlan(User $user, CardSubscription $subscription): bool
    {
        return $user->hasAnyRole(['operations-l2', 'compliance-manager', 'super-admin']);
    }

    public function retryPayment(User $user, CardSubscription $subscription): bool
    {
        return $this->update($user, $subscription);
    }

    public function reactivate(User $user, CardSubscription $subscription): bool
    {
        return $this->update($user, $subscription);
    }

    public function minorOverrideApprove(User $user, CardSubscription $subscription): bool
    {
        return $user->hasRole('super-admin');
    }
}
