<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Services;

use App\Domain\Corporate\Enums\CorporateCapability;
use App\Models\ApiKey;
use App\Models\Team;
use App\Models\User;

class CorporateApiAdminService
{
    public function __construct(
        private readonly CorporateCapabilityGate $gate,
    ) {
    }

    /**
     * Revoke an API key, enforcing API_ADMINISTRATION for business teams.
     */
    public function revokeApiKey(User $actingUser, Team $team, ApiKey $apiKey): void
    {
        if ($team->is_business_organization && ! $actingUser->ownsTeam($team)) {
            $this->gate->authorize($actingUser, $team, CorporateCapability::API_ADMINISTRATION);
        }

        $apiKey->revoke();
    }

    /**
     * Re-activate a previously revoked API key, enforcing API_ADMINISTRATION for business teams.
     */
    public function activateApiKey(User $actingUser, Team $team, ApiKey $apiKey): void
    {
        if ($team->is_business_organization && ! $actingUser->ownsTeam($team)) {
            $this->gate->authorize($actingUser, $team, CorporateCapability::API_ADMINISTRATION);
        }

        $apiKey->forceFill(['is_active' => true])->save();
    }
}
