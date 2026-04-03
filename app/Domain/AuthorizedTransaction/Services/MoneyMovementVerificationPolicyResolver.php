<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Services;

use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Contracts\MoneyMovementRiskSignalProviderInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Shared\Money\MoneyConverter;
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
        $amountMinor = (int) MoneyConverter::forAsset($amount, $asset);
        $stepUpThresholdMinor = (int) MoneyConverter::toSmallestUnit(
            (string) config('maphapay_migration.money_movement.send_money.step_up_threshold', '100.00'),
            $asset->precision,
        );

        $userPreference = $this->userHasTransactionPin($user)
            ? AuthorizedTransaction::VERIFICATION_PIN
            : AuthorizedTransaction::VERIFICATION_NONE;

        $riskDecision = $this->riskSignals->evaluateInitiation(
            user: $user,
            operationType: AuthorizedTransaction::REMARK_SEND_MONEY,
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
}
