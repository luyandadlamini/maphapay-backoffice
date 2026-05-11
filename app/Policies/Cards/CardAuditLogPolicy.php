<?php

declare(strict_types=1);

namespace App\Policies\Cards;

use App\Domain\CardSubscriptions\Models\CardAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CardAuditLogPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['compliance-manager', 'super-admin']);
    }

    public function view(User $user, CardAuditLog $log): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CardAuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, CardAuditLog $log): bool
    {
        return false;
    }

    public function forceDelete(User $user, CardAuditLog $log): bool
    {
        return false;
    }

    public function restore(User $user, CardAuditLog $log): bool
    {
        return false;
    }

    public function export(User $user, CardAuditLog $log): bool
    {
        return $user->hasAnyRole(['compliance-manager', 'super-admin']);
    }
}
