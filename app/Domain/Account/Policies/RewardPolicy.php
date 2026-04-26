<?php

declare(strict_types=1);

namespace App\Domain\Account\Policies;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class RewardPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly ?MinorAccountAccessService $accessService = null,
    ) {
    }

    public function view(User $user, Account $minorAccount): bool
    {
        return $this->accessService()->canView($user, $minorAccount);
    }

    public function create(User $user, Account $minorAccount): bool
    {
        return $this->accessService()->hasGuardianAccess($user, $minorAccount);
    }

    public function approve(User $user, Account $minorAccount): bool
    {
        return $this->accessService()->hasGuardianAccess($user, $minorAccount);
    }

    public function reject(User $user, Account $minorAccount): bool
    {
        return $this->accessService()->hasGuardianAccess($user, $minorAccount);
    }

    private function accessService(): MinorAccountAccessService
    {
        return $this->accessService ?? app(MinorAccountAccessService::class);
    }
}
