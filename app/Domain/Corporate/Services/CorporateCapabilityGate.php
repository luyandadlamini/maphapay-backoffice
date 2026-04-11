<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Services;

use App\Domain\Corporate\Enums\CorporateCapability;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class CorporateCapabilityGate
{
    public function allows(User $user, Team $team, CorporateCapability|string $capability): bool
    {
        if ($user->ownsTeam($team)) {
            return true;
        }

        if (! $team->is_business_organization) {
            return false;
        }

        // Use a fresh query to avoid stale relation caches (e.g. when the profile
        // is created after the team object was first loaded in the same request).
        $profile = $team->corporateProfile()->first();

        if (! $profile) {
            return false;
        }

        $capabilityValue = $capability instanceof CorporateCapability ? $capability->value : $capability;

        return $profile->capabilityGrants()
            ->where('user_id', $user->id)
            ->where('capability', $capabilityValue)
            ->exists();
    }

    public function authorize(User $user, Team $team, CorporateCapability|string $capability): void
    {
        if (! $this->allows($user, $team, $capability)) {
            throw new AuthorizationException('The authenticated user does not have the required corporate capability.');
        }
    }
}
