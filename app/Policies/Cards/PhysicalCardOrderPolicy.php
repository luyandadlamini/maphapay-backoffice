<?php

declare(strict_types=1);

namespace App\Policies\Cards;

use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PhysicalCardOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['support-l1', 'fraud-analyst', 'compliance-manager', 'operations-l2', 'super-admin']);
    }

    public function view(User $user, PhysicalCardOrder $order): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PhysicalCardOrder $order): bool
    {
        return $user->hasAnyRole(['operations-l2', 'compliance-manager', 'super-admin']);
    }

    public function delete(User $user, PhysicalCardOrder $order): bool
    {
        return false;
    }

    public function forceDelete(User $user, PhysicalCardOrder $order): bool
    {
        return false;
    }

    public function restore(User $user, PhysicalCardOrder $order): bool
    {
        return false;
    }
}
