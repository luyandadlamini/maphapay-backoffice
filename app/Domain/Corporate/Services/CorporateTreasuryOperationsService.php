<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Services;

use App\Domain\Corporate\Enums\CorporateCapability;
use App\Models\Team;
use App\Models\User;

class CorporateTreasuryOperationsService
{
    public function __construct(
        private readonly CorporateCapabilityGate $gate,
    ) {
    }

    /**
     * Authorize and record a treasury allocation in the corporate context.
     *
     * This is the capability-enforcement boundary for TREASURY_OPERATIONS.
     * Actual treasury execution is out of scope for this slice.
     *
     * @return array{authorized: true, reference: string, amount: int, asset: string, authorized_by: int}
     */
    public function authorizeAndRecordAllocation(
        User $actingUser,
        Team $team,
        string $allocationReference,
        int $amountMinorUnits,
        string $assetCode,
    ): array {
        if ($team->is_business_organization && ! $actingUser->ownsTeam($team)) {
            $this->gate->authorize($actingUser, $team, CorporateCapability::TREASURY_OPERATIONS);
        }

        return [
            'authorized'     => true,
            'reference'      => $allocationReference,
            'amount'         => $amountMinorUnits,
            'asset'          => $assetCode,
            'authorized_by'  => $actingUser->id,
        ];
    }
}
