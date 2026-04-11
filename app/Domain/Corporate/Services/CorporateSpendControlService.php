<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Services;

use App\Domain\Corporate\Enums\CorporateCapability;
use App\Domain\MachinePay\Models\MppSpendingLimit;
use App\Models\Team;
use App\Models\User;

class CorporateSpendControlService
{
    public function __construct(
        private readonly CorporateCapabilityGate $gate,
    ) {}

    /**
     * Configure an MPP agent spending limit for the team, enforcing
     * SPEND_CONTROL_ADMINISTRATION capability on business teams.
     */
    public function configureAgentSpendingLimit(
        User $actingUser,
        Team $team,
        string $agentId,
        int $dailyLimit,
        int $perTxLimit,
    ): MppSpendingLimit {
        if ($team->is_business_organization) {
            $this->gate->authorize($actingUser, $team, CorporateCapability::SPEND_CONTROL_ADMINISTRATION);
        }

        /** @var MppSpendingLimit $limit */
        $limit = MppSpendingLimit::updateOrCreate(
            [
                'agent_id' => $agentId,
                'team_id'  => $team->id,
            ],
            [
                'daily_limit'  => $dailyLimit,
                'per_tx_limit' => $perTxLimit,
            ],
        );

        return $limit;
    }
}
