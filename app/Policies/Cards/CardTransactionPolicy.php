<?php

declare(strict_types=1);

namespace App\Policies\Cards;

use App\Domain\CardSubscriptions\Models\CardTransaction;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CardTransactionPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['support-l1', 'fraud-analyst', 'compliance-manager', 'operations-l2', 'super-admin']);
    }

    public function view(User $user, CardTransaction $transaction): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['support-l1', 'fraud-analyst', 'operations-l2', 'compliance-manager', 'super-admin']);
    }

    public function update(User $user, CardTransaction $transaction): bool
    {
        return false;
    }

    public function delete(User $user, CardTransaction $transaction): bool
    {
        return false;
    }

    public function forceDelete(User $user, CardTransaction $transaction): bool
    {
        return false;
    }

    public function restore(User $user, CardTransaction $transaction): bool
    {
        return false;
    }

    public function export(User $user, CardTransaction $transaction): bool
    {
        return $user->hasAnyRole(['compliance-manager', 'operations-l2', 'super-admin']);
    }
}
