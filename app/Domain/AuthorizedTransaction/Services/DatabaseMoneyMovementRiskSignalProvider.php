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
        if ($this->hasAmountAnomaly($user, $amount, $assetCode)) {
            return [
                'step_up' => true,
                'reason'  => 'amount_anomaly_detected',
            ];
        }

        if ($this->hasRecipientChurnSignal($user, $context)) {
            return [
                'step_up' => true,
                'reason'  => 'recipient_churn_detected',
            ];
        }

        if ($this->hasRecentVerificationFailures($user)) {
            return [
                'step_up' => true,
                'reason'  => 'recent_verification_failures',
            ];
        }

        if ($this->hasHighRecentVelocity($user)) {
            return [
                'step_up' => true,
                'reason'  => 'velocity_limit_exceeded',
            ];
        }

        return [
            'step_up' => false,
            'reason'  => null,
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
            'allow'  => true,
            'reason' => null,
        ];
    }

    private function hasAmountAnomaly(User $user, string $amount, string $assetCode): bool
    {
        $multiplier = (float) config('maphapay_migration.money_movement.risk_signals.amount_anomaly.multiplier', 0);
        if ($multiplier <= 1) {
            return false;
        }

        $minSamples = max(
            1,
            (int) config('maphapay_migration.money_movement.risk_signals.amount_anomaly.min_samples', 3),
        );
        $lookbackMinutes = max(
            1,
            (int) config('maphapay_migration.money_movement.risk_signals.amount_anomaly.lookback_minutes', 1440),
        );

        $recentAmounts = AuthorizedTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('remark', $this->moneyMovementRemarks())
            ->where('status', AuthorizedTransaction::STATUS_COMPLETED)
            ->where('created_at', '>=', now()->subMinutes($lookbackMinutes))
            ->get()
            ->map(static function (AuthorizedTransaction $transaction) use ($assetCode): ?float {
                $payload = is_array($transaction->payload) ? $transaction->payload : [];
                $transactionAssetCode = (string) ($payload['asset_code'] ?? $transaction->result['asset_code'] ?? '');
                if ($transactionAssetCode !== $assetCode) {
                    return null;
                }

                $transactionAmount = $payload['amount'] ?? $transaction->result['amount'] ?? null;
                if (! is_scalar($transactionAmount) || ! is_numeric((string) $transactionAmount)) {
                    return null;
                }

                return (float) $transactionAmount;
            })
            ->filter(static fn (?float $value): bool => $value !== null && $value > 0)
            ->values();

        if ($recentAmounts->count() < $minSamples) {
            return false;
        }

        $baselineAverage = (float) $recentAmounts->avg();
        if ($baselineAverage <= 0) {
            return false;
        }

        return (float) $amount >= ($baselineAverage * $multiplier);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function hasRecipientChurnSignal(User $user, array $context): bool
    {
        $maxDistinctCounterparties = (int) config(
            'maphapay_migration.money_movement.risk_signals.recipient_churn.max_distinct_counterparties',
            0,
        );
        if ($maxDistinctCounterparties <= 0) {
            return false;
        }

        $currentCounterparty = $this->resolveCounterpartyKey($context);
        if ($currentCounterparty === null) {
            return false;
        }

        $lookbackMinutes = max(
            1,
            (int) config('maphapay_migration.money_movement.risk_signals.recipient_churn.lookback_minutes', 1440),
        );

        $recentCounterparties = AuthorizedTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('remark', $this->moneyMovementRemarks())
            ->where('created_at', '>=', now()->subMinutes($lookbackMinutes))
            ->get()
            ->map(fn (AuthorizedTransaction $transaction): ?string => $this->resolveCounterpartyKey(
                is_array($transaction->payload) ? $transaction->payload : [],
            ))
            ->filter(static fn (?string $value): bool => $value !== null && $value !== '')
            ->unique()
            ->values();

        return $recentCounterparties->count() >= $maxDistinctCounterparties
            && ! $recentCounterparties->contains($currentCounterparty);
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

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveCounterpartyKey(array $context): ?string
    {
        foreach (['recipient_user_id', 'requester_user_id', 'recipient_account_uuid', 'to_account_uuid'] as $key) {
            $value = $context[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
