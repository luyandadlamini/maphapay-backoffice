<?php

declare(strict_types=1);

namespace App\Policies\Cards;

use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CardDisputePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['support-l1', 'fraud-analyst', 'compliance-manager', 'operations-l2', 'super-admin']);
    }

    public function view(User $user, CardDispute $dispute): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['support-l1', 'fraud-analyst', 'operations-l2', 'compliance-manager', 'super-admin']);
    }

    public function update(User $user, CardDispute $dispute): bool
    {
        return $user->hasAnyRole(['fraud-analyst', 'operations-l2', 'compliance-manager', 'super-admin']);
    }

    public function delete(User $user, CardDispute $dispute): bool
    {
        return false;
    }

    public function forceDelete(User $user, CardDispute $dispute): bool
    {
        return false;
    }

    public function restore(User $user, CardDispute $dispute): bool
    {
        return false;
    }
}
