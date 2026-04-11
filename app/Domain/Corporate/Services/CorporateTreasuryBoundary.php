<?php

declare(strict_types=1);

namespace App\Domain\Corporate\Services;

use App\Domain\Corporate\Models\CorporateProfile;
use App\Domain\Corporate\Models\CorporateTreasuryAccount;
use App\Domain\MachinePay\Models\MppSpendingLimit;

class CorporateTreasuryBoundary
{
    /**
     * Register an account as the treasury account for this corporate profile.
     */
    public function registerTreasuryAccount(
        CorporateProfile $profile,
        string $accountId,
        ?string $assetCode = null,
        ?string $label = null,
    ): CorporateTreasuryAccount {
        return $this->register($profile, $accountId, 'treasury', $assetCode, $label);
    }

    /**
     * Register an account as a spend account for this corporate profile.
     */
    public function registerSpendAccount(
        CorporateProfile $profile,
        string $accountId,
        ?string $assetCode = null,
        ?string $label = null,
    ): CorporateTreasuryAccount {
        return $this->register($profile, $accountId, 'spend', $assetCode, $label);
    }

    /**
     * Resolve the primary (first active) treasury account for a corporate profile.
     */
    public function resolveTreasuryAccount(CorporateProfile $profile): ?CorporateTreasuryAccount
    {
        /** @var CorporateTreasuryAccount|null */
        return CorporateTreasuryAccount::query()
            ->where('corporate_profile_id', $profile->id)
            ->where('account_type', 'treasury')
            ->where('is_active', true)
            ->first();
    }

    /**
     * Resolve all active spend accounts for a corporate profile.
     *
     * @return CorporateTreasuryAccount[]
     */
    public function resolveSpendAccounts(CorporateProfile $profile): array
    {
        return CorporateTreasuryAccount::query()
            ->where('corporate_profile_id', $profile->id)
            ->where('account_type', 'spend')
            ->where('is_active', true)
            ->get()
            ->all();
    }

    /**
     * Anchor an MppSpendingLimit to the corporate profile's team,
     * associating the agent's spending limit with the corporate boundary.
     */
    public function anchorMppSpendingLimit(
        CorporateProfile $profile,
        MppSpendingLimit $limit,
    ): MppSpendingLimit {
        $limit->forceFill(['team_id' => $profile->team_id])->save();

        return $limit;
    }

    /**
     * Check whether the given account ID is the active treasury account for this profile.
     */
    public function isTreasuryAccount(CorporateProfile $profile, string $accountId): bool
    {
        return CorporateTreasuryAccount::query()
            ->where('corporate_profile_id', $profile->id)
            ->where('treasury_account_id', $accountId)
            ->where('account_type', 'treasury')
            ->where('is_active', true)
            ->exists();
    }

    private function register(
        CorporateProfile $profile,
        string $accountId,
        string $accountType,
        ?string $assetCode,
        ?string $label,
    ): CorporateTreasuryAccount {
        /** @var CorporateTreasuryAccount $account */
        $account = CorporateTreasuryAccount::query()->updateOrCreate(
            [
                'corporate_profile_id' => $profile->id,
                'treasury_account_id'  => $accountId,
            ],
            [
                'account_type' => $accountType,
                'asset_code'   => $assetCode,
                'label'        => $label,
                'is_active'    => true,
            ],
        );

        return $account;
    }
}
