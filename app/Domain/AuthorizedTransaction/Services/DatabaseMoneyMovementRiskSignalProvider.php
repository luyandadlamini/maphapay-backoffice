<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Services;

use App\Domain\AuthorizedTransaction\Contracts\MoneyMovementRiskSignalProviderInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\User;

class DatabaseMoneyMovementRiskSignalProvider implements MoneyMovementRiskSignalProviderInterface
{
    /**
     * @param  array<string, mixed>  $context
     * @return array{step_up: bool, reason: string|null}
     */
    public function evaluateInitiation(
        User $user,
        string $operationType,
        string $amount,
        string $assetCode,
        array $context = [],
    ): array {
        if ($this->hasRecentVerificationFailures($user)) {
            return [
                'step_up' => true,
                'reason' => 'recent_verification_failures',
            ];
        }

        if ($this->hasHighRecentVelocity($user)) {
            return [
                'step_up' => true,
                'reason' => 'velocity_limit_exceeded',
            ];
        }

        return [
            'step_up' => false,
            'reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{allow: bool, reason: string|null}
     */
    public function evaluatePreExecution(
        AuthorizedTransaction $transaction,
        array $context = [],
    ): array {
        return [
            'allow' => true,
            'reason' => null,
        ];
    }

    private function hasRecentVerificationFailures(User $user): bool
    {
        $maxFailures = (int) config('maphapay_migration.money_movement.risk_signals.verification_failures.max_failures', 0);
        if ($maxFailures <= 0) {
            return false;
        }

        $lookbackMinutes = max(
            1,
            (int) config('maphapay_migration.money_movement.risk_signals.verification_failures.lookback_minutes', 30),
        );

        $failureCount = (int) AuthorizedTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('remark', $this->moneyMovementRemarks())
            ->where('created_at', '>=', now()->subMinutes($lookbackMinutes))
            ->sum('verification_failures');

        return $failureCount >= $maxFailures;
    }

    private function hasHighRecentVelocity(User $user): bool
    {
        $maxInitiations = (int) config('maphapay_migration.money_movement.risk_signals.velocity.max_initiations', 0);
        if ($maxInitiations <= 0) {
            return false;
        }

        $lookbackMinutes = max(
            1,
            (int) config('maphapay_migration.money_movement.risk_signals.velocity.lookback_minutes', 15),
        );

        $recentInitiationCount = AuthorizedTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('remark', $this->moneyMovementRemarks())
            ->where('created_at', '>=', now()->subMinutes($lookbackMinutes))
            ->count();

        return $recentInitiationCount >= $maxInitiations;
    }

    /**
     * @return list<string>
     */
    private function moneyMovementRemarks(): array
    {
        return [
            AuthorizedTransaction::REMARK_SEND_MONEY,
            AuthorizedTransaction::REMARK_REQUEST_MONEY,
            AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
        ];
    }
}
