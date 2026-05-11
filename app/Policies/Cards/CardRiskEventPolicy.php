<?php

declare(strict_types=1);

namespace App\Policies\Cards;

use App\Domain\CardSubscriptions\Models\CardRiskEvent;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CardRiskEventPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['fraud-analyst', 'compliance-manager', 'operations-l2', 'super-admin']);
    }

    public function view(User $user, CardRiskEvent $event): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CardRiskEvent $event): bool
    {
        return $user->hasAnyRole(['fraud-analyst', 'operations-l2', 'compliance-manager', 'super-admin']);
    }

    public function delete(User $user, CardRiskEvent $event): bool
    {
        return false;
    }

    public function forceDelete(User $user, CardRiskEvent $event): bool
    {
        return false;
    }

    public function restore(User $user, CardRiskEvent $event): bool
    {
        return false;
    }
}
