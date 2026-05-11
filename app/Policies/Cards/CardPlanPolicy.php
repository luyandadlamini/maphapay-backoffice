<?php

declare(strict_types=1);

namespace App\Policies\Cards;

use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CardPlanPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function view(User $user, CardPlan $plan): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CardPlan $plan): bool
    {
        return $user->hasRole('super-admin');
    }

    public function delete(User $user, CardPlan $plan): bool
    {
        return false;
    }

    public function forceDelete(User $user, CardPlan $plan): bool
    {
        return false;
    }

    public function restore(User $user, CardPlan $plan): bool
    {
        return false;
    }
}
