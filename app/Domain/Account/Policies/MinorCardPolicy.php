<?php

declare(strict_types=1);

namespace App\Domain\Account\Policies;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorCardRequest;
use App\Domain\Account\Services\MinorAccountAccessService;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class MinorCardPolicy
{
    use HandlesAuthorization;

    public function __construct(
        private readonly ?MinorAccountAccessService $accessService = null,
    ) {
    }

    public function request(User $user, Account $minorAccount): bool
    {
        return $this->accessService()->hasGuardianAccess($user, $minorAccount);
    }

    public function approve(User $user, MinorCardRequest $request): bool
    {
        $minorAccount = $request->minorAccount;

        return $minorAccount instanceof Account
            && $this->accessService()->hasGuardianAccess($user, $minorAccount);
    }

    public function deny(User $user, MinorCardRequest $request): bool
    {
        $minorAccount = $request->minorAccount;

        return $minorAccount instanceof Account
            && $this->accessService()->hasGuardianAccess($user, $minorAccount);
    }

    public function freeze(User $user, Account $minorAccount): bool
    {
        return $this->accessService()->hasGuardianAccess($user, $minorAccount);
    }

    public function unfreeze(User $user, Account $minorAccount): bool
    {
        return $this->accessService()->hasGuardianAccess($user, $minorAccount);
    }

    public function view(User $user, Account|MinorCardRequest $subject): bool
    {
        $minorAccount = $subject instanceof Account
            ? $subject
            : $subject->minorAccount;

        return $minorAccount instanceof Account
            && $this->accessService()->canView($user, $minorAccount);
    }

    private function accessService(): MinorAccountAccessService
    {
        return $this->accessService ?? app(MinorAccountAccessService::class);
    }
}
