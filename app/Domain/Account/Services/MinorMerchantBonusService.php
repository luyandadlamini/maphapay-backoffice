<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\MinorMerchantBonusTransaction;
use App\Models\MerchantPartner;
use Illuminate\Support\Str;

class MinorMerchantBonusService
{
    private const POINTS_PER_SZL = 0.1;

    private const MAX_BONUS_POINTS = 5;

    private const MAX_MULTIPLIER = 5.0;

    public function calculateBonusPoints(float $amountSzl, float $multiplier): int
    {
        $multiplier = min($multiplier, self::MAX_MULTIPLIER);
        $points = $amountSzl * self::POINTS_PER_SZL * $multiplier;

        return (int) min(floor($points), self::MAX_BONUS_POINTS);
    }

    /**
     * @return array{bonus_points_awarded: int, multiplier_applied: float, reason?: string, already_awarded?: bool}
     */
    public function awardBonus(
        string $parentTransactionUuid,
        int $merchantPartnerId,
        string $minorAccountUuid,
        float $amountSzl,
        ?int $minorAge = null
    ): array {
        /** @var MinorMerchantBonusTransaction|null $existing */
        $existing = MinorMerchantBonusTransaction::findByParentTransaction($parentTransactionUuid);

        if ($existing !== null) {
            return [
                'bonus_points_awarded' => 0,
                'multiplier_applied'   => 0.0,
                'already_awarded'      => true,
            ];
        }

        $partner = MerchantPartner::findOrFail($merchantPartnerId);

        if (! $partner->isActiveForMinors()) {
            $this->recordBonusTransaction(
                $merchantPartnerId,
                $minorAccountUuid,
                $parentTransactionUuid,
                0,
                0.0,
                $amountSzl,
                'failed',
                'Merchant not active for minors'
            );

            return [
                'bonus_points_awarded' => 0,
                'multiplier_applied'   => 0.0,
                'reason'               => 'not_eligible',
            ];
        }

        if ($minorAge !== null && $minorAge < $partner->getMinAgeAllowance()) {
            $this->recordBonusTransaction(
                $merchantPartnerId,
                $minorAccountUuid,
                $parentTransactionUuid,
                0,
                0.0,
                $amountSzl,
                'failed',
                'Minor below minimum age allowance'
            );

            return [
                'bonus_points_awarded' => 0,
                'multiplier_applied'   => 0.0,
                'reason'               => 'age_restriction',
            ];
        }

        $multiplier = min($partner->getBonusMultiplier(), self::MAX_MULTIPLIER);
        $points = $this->calculateBonusPoints($amountSzl, $multiplier);

        if ($points > 0) {
            $this->recordBonusTransaction(
                $merchantPartnerId,
                $minorAccountUuid,
                $parentTransactionUuid,
                $points,
                $multiplier,
                $amountSzl,
                'awarded'
            );
        }

        return [
            'bonus_points_awarded' => $points,
            'multiplier_applied'   => $multiplier,
            'reason'               => $points > 0 ? 'success' : 'no_points',
        ];
    }

    /**
     * @return array{merchant_partner_id: int, merchant_name: string, bonus_multiplier: float, min_age_allowance: int, category_slugs: array|null, is_active_for_minors: bool, bonus_terms: string|null}
     */
    public function getBonusDetails(int $merchantPartnerId): array
    {
        $partner = MerchantPartner::findOrFail($merchantPartnerId);

        return [
            'merchant_partner_id'  => $partner->id,
            'merchant_name'        => $partner->name,
            'bonus_multiplier'     => $partner->getBonusMultiplier(),
            'min_age_allowance'    => $partner->getMinAgeAllowance(),
            'category_slugs'       => $partner->category_slugs,
            'is_active_for_minors' => $partner->isActiveForMinors(),
            'bonus_terms'          => $partner->bonus_terms,
        ];
    }

    private function recordBonusTransaction(
        int $merchantPartnerId,
        string $minorAccountUuid,
        string $parentTransactionUuid,
        int $bonusPoints,
        float $multiplier,
        float $amountSzl,
        string $status,
        ?string $errorReason = null
    ): MinorMerchantBonusTransaction {
        return MinorMerchantBonusTransaction::create([
            'id'                      => Str::uuid()->toString(),
            'merchant_partner_id'     => $merchantPartnerId,
            'minor_account_uuid'      => $minorAccountUuid,
            'parent_transaction_uuid' => $parentTransactionUuid,
            'bonus_points_awarded'    => $bonusPoints,
            'multiplier_applied'      => $multiplier,
            'amount_szl'              => $amountSzl,
            'status'                  => $status,
            'error_reason'            => $errorReason,
        ]);
    }
}
