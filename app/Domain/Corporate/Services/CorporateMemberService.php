<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Services;

use App\Domain\Corporate\Enums\CorporateCapability;
use App\Models\Team;
use App\Models\TeamUserRole;
use App\Models\User;

class CorporateMemberService
{
    public function __construct(
        private readonly CorporateCapabilityGate $gate,
    ) {
    }

    /**
     * Assign a role to a team member, enforcing MEMBER_ADMINISTRATION capability
     * for business teams when the acting user is not the team owner.
     *
     * @param  array<string, mixed>|null  $permissions
     */
    public function assignRole(
        User $actingUser,
        Team $team,
        User $targetUser,
        string $role,
        ?array $permissions = null,
    ): TeamUserRole {
        if ($team->is_business_organization && ! $actingUser->ownsTeam($team)) {
            $this->gate->authorize($actingUser, $team, CorporateCapability::MEMBER_ADMINISTRATION);
        }

        /** @var TeamUserRole $teamUserRole */
        $teamUserRole = $team->assignUserRole($targetUser, $role, $permissions);

        return $teamUserRole;
    }
}
