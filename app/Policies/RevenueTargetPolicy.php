<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Analytics\Models\RevenueTarget;
use App\Models\User;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

class RevenueTargetPolicy
{
    use HandlesAuthorization;

    private function allowed(User $user): bool
    {
        $access = app(BackofficeWorkspaceAccess::class);

        return $access->canAccess('finance', $user)
            || $access->canAccess('platform_administration', $user);
    }

    public function viewAny(User $user): bool
    {
        return $this->allowed($user);
    }

    public function view(User $user, RevenueTarget $revenueTarget): bool
    {
        return $this->allowed($user);
    }

    public function create(User $user): bool
    {
        return $this->allowed($user);
    }

    public function update(User $user, RevenueTarget $revenueTarget): bool
    {
        return $this->allowed($user);
    }

    public function delete(User $user, RevenueTarget $revenueTarget): bool
    {
        return $this->allowed($user);
    }
}
