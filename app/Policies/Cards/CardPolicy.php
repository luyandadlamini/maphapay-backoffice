<?php

declare(strict_types=1);

namespace App\Policies\Cards;

use App\Domain\CardIssuance\Models\Card;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CardPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['support-l1', 'fraud-analyst', 'compliance-manager', 'operations-l2', 'super-admin']);
    }

    public function view(User $user, Card $card): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Card $card): bool
    {
        return $user->hasAnyRole(['operations-l2', 'compliance-manager', 'super-admin']);
    }

    public function delete(User $user, Card $card): bool
    {
        return false;
    }

    public function forceDelete(User $user, Card $card): bool
    {
        return false;
    }

    public function restore(User $user, Card $card): bool
    {
        return false;
    }

    public function adminFreeze(User $user, Card $card): bool
    {
        return $user->hasAnyRole(['fraud-analyst', 'operations-l2', 'compliance-manager', 'super-admin']);
    }

    public function adminUnfreeze(User $user, Card $card): bool
    {
        return $this->update($user, $card);
    }

    public function markLostStolen(User $user, Card $card): bool
    {
        return $this->update($user, $card);
    }

    public function adminCancel(User $user, Card $card): bool
    {
        return $user->hasAnyRole(['operations-l2', 'compliance-manager', 'super-admin']);
    }
}
