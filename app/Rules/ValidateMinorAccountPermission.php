<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\TransactionProjection;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidateMinorAccountPermission implements ValidationRule
{
    private const BLOCKED_TRANSACTION_TYPES = [
        'alcohol',
        'tobacco',
        'gambling',
        'adult_content',
    ];

    private const SPENDING_TRANSACTION_TYPES = [
        'withdrawal',
        'transfer',
        'transfer_debit',
        'debit',
        'purchase',
        'payment',
    ];

    public function __construct(
        private readonly Account $minorAccount,
        private readonly string $transactionType,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $permissionLevel = (int) ($this->minorAccount->permission_level ?? 0);

        if ($permissionLevel <= 2) {
            $fail('This minor account cannot perform spending transactions at its current permission level.');

            return;
        }

        if (in_array($this->transactionType, self::BLOCKED_TRANSACTION_TYPES, true)) {
            $fail('This transaction category is not allowed for minor accounts.');

            return;
        }

        [$dailyLimit, $monthlyLimit] = $this->resolveLimits($permissionLevel);

        if ($dailyLimit === null || $monthlyLimit === null) {
            return;
        }

        $amount = (int) $value;
        $todaySpend = $this->baseSpendQuery()
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->sum('amount');

        if (($todaySpend + $amount) > $dailyLimit) {
            $fail('This transaction exceeds the daily spending limit for the minor account.');

            return;
        }

        $monthlySpend = $this->baseSpendQuery()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount');

        if (($monthlySpend + $amount) > $monthlyLimit) {
            $fail('This transaction exceeds the monthly spending limit for the minor account.');
        }
    }

    private function baseSpendQuery()
    {
        return TransactionProjection::query()
            ->where('account_uuid', $this->minorAccount->uuid)
            ->whereIn('type', self::SPENDING_TRANSACTION_TYPES)
            ->where('status', 'completed');
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveLimits(int $permissionLevel): array
    {
        return match (true) {
            $permissionLevel <= 4  => [50_000, 500_000],
            $permissionLevel === 5 => [100_000, 1_000_000],
            $permissionLevel <= 7  => [200_000, 1_500_000],
            default                => [null, null],
        };
    }
}
