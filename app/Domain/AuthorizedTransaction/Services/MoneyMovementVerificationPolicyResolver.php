<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Services;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Contracts\MoneyMovementRiskSignalProviderInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Shared\Money\MoneyConverter;
use App\Models\Setting;
use App\Models\User;

class MoneyMovementVerificationPolicyResolver
{
    public function __construct(
        private readonly MoneyMovementRiskSignalProviderInterface $riskSignals,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *   verification_type: string,
     *   next_step: 'otp'|'pin'|'none',
     *   reason: string,
     *   risk_reason: string|null,
     *   client_hint: string|null,
     *   user_preference: string,
     *   amount_minor: int,
     *   step_up_threshold_minor: int
     * }
     */
    public function resolveSendMoneyPolicy(
        User $user,
        string $amount,
        Asset $asset,
        ?string $clientHint = null,
        array $context = [],
    ): array {
        return $this->resolvePolicy(
            user: $user,
            amount: $amount,
            asset: $asset,
            operationType: AuthorizedTransaction::REMARK_SEND_MONEY,
            clientHint: $clientHint,
            allowNone: true,
            stepUpThresholdConfig: 'maphapay_migration.money_movement.send_money.step_up_threshold',
            context: $context,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *   verification_type: string,
     *   next_step: 'otp'|'pin'|'none',
     *   reason: string,
     *   risk_reason: string|null,
     *   client_hint: string|null,
     *   user_preference: string,
     *   amount_minor: int,
     *   step_up_threshold_minor: int
     * }
     */
    public function resolveRequestMoneyPolicy(
        User $user,
        string $amount,
        Asset $asset,
        string $operationType,
        ?string $clientHint = null,
        array $context = [],
    ): array {
        return $this->resolvePolicy(
            user: $user,
            amount: $amount,
            asset: $asset,
            operationType: $operationType,
            clientHint: $clientHint,
            allowNone: false,
            stepUpThresholdConfig: 'maphapay_migration.money_movement.request_money.step_up_threshold',
            context: $context,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *   verification_type: string,
     *   next_step: 'otp'|'pin'|'none',
     *   reason: string,
     *   risk_reason: string|null,
     *   client_hint: string|null,
     *   user_preference: string,
     *   amount_minor: int,
     *   step_up_threshold_minor: int
     * }
     */
    private function resolvePolicy(
        User $user,
        string $amount,
        Asset $asset,
        string $operationType,
        ?string $clientHint,
        bool $allowNone,
        string $stepUpThresholdConfig,
        array $context = [],
    ): array {
        $amountMinor = (int) MoneyConverter::forAsset($amount, $asset);
        $stepUpThresholdAmount = $operationType === AuthorizedTransaction::REMARK_SEND_MONEY
            ? $this->resolveSendMoneyStepUpThresholdAmount($user)
            : (string) config($stepUpThresholdConfig, '100.00');
        $stepUpThresholdMinor = (int) MoneyConverter::toSmallestUnit($stepUpThresholdAmount, $asset->precision);

        $userPreference = $this->userHasEnabledTransactionPin($user)
            ? AuthorizedTransaction::VERIFICATION_PIN
            : ($allowNone ? AuthorizedTransaction::VERIFICATION_NONE : AuthorizedTransaction::VERIFICATION_OTP);

        $riskDecision = $this->riskSignals->evaluateInitiation(
            user: $user,
            operationType: $operationType,
            amount: $amount,
            assetCode: $asset->code,
            context: array_merge($context, [
                'amount_minor' => $amountMinor,
                'client_hint' => $clientHint,
            ]),
        );

        $requiresStepUp = false;
        $reason = 'user_preference';
        $riskReason = null;

        if (($riskDecision['step_up'] ?? false) === true) {
            $requiresStepUp = true;
            $reason = 'risk_signal';
            $riskReason = $riskDecision['reason'] ?? 'risk_signal';
        } elseif ($amountMinor >= $stepUpThresholdMinor) {
            $requiresStepUp = true;
            $reason = 'amount_threshold';
            $riskReason = 'amount_threshold_exceeded';
        }

        $verificationType = $requiresStepUp
            ? ($this->userHasTransactionPin($user)
                ? AuthorizedTransaction::VERIFICATION_PIN
                : AuthorizedTransaction::VERIFICATION_OTP)
            : $userPreference;

        return [
            'verification_type' => $verificationType,
            'next_step' => $verificationType === AuthorizedTransaction::VERIFICATION_OTP ? 'otp' : $verificationType,
            'reason' => $reason,
            'risk_reason' => $riskReason,
            'client_hint' => $clientHint,
            'user_preference' => $userPreference,
            'amount_minor' => $amountMinor,
            'step_up_threshold_minor' => $stepUpThresholdMinor,
        ];
    }

    private function userHasTransactionPin(User $user): bool
    {
        return (bool) ($user->transaction_pin_set ?? false)
            || (is_string($user->getRawOriginal('transaction_pin')) && $user->getRawOriginal('transaction_pin') !== '');
    }

    private function userHasEnabledTransactionPin(User $user): bool
    {
        return $this->userHasTransactionPin($user) && (bool) ($user->transaction_pin_enabled ?? false);
    }

    private function resolveSendMoneyStepUpThresholdAmount(User $user): string
    {
        $override = $user->send_money_step_up_threshold_override;
        if (is_numeric($override)) {
            return number_format((float) $override, 2, '.', '');
        }

        return match ($this->resolveSendMoneyThresholdTier($user)) {
            'low' => $this->settingAmount('send_money_threshold_low_enhanced_or_full', '5000.00'),
            'medium' => $this->settingAmount('send_money_threshold_medium_or_standard', '2500.00'),
            default => $this->settingAmount('send_money_threshold_high_or_basic', '1000.00'),
        };
    }

    private function resolveSendMoneyThresholdTier(User $user): string
    {
        $riskRank = match (strtolower((string) ($user->risk_rating ?? ''))) {
            'low' => 1,
            'medium' => 2,
            default => 3,
        };

        $kycRank = match (strtolower((string) ($user->kyc_level ?? ''))) {
            'full', 'enhanced' => 1,
            'standard' => 2,
            default => 3,
        };

        return match (max($riskRank, $kycRank)) {
            1 => 'low',
            2 => 'medium',
            default => 'high',
        };
    }

    private function settingAmount(string $key, string $fallback): string
    {
        $value = Setting::get($key, $fallback);

        if (! is_numeric($value)) {
            return $fallback;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
