<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\TransactionProjection;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidateMinorAccountPermission implements ValidationRule
{
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

        if (in_array($this->transactionType, config('minor_family.blocked_merchant_categories'), true)) {
            $fail('This transaction category is not allowed for minor accounts.');

            return;
        }

        [$dailyLimit, $monthlyLimit] = $this->resolveLimits($permissionLevel);

        if ($dailyLimit === null || $monthlyLimit === null) {
            return;
        }

        $amount = (int) round((float) $value * 100);
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
        $limits = config('minor_family.spend_limit_level_' . $permissionLevel);

        return $limits ?? [null, null];
    }

    /**
     * Returns the per-transaction SZL amount above which guardian approval is required.
     * Level 1-2 → 0 (all transactions require approval / are blocked).
     * Level 3-4 → 100 SZL
     * Level 5-6 → 1000 SZL
     * Level 7   → 2000 SZL
     * Level 8+  → null (no approval needed — full autonomy).
     *
     * Amount is compared as a float (major-unit string from request).
     */
    public static function approvalThresholdFor(int $permissionLevel): ?float
    {
        return match (true) {
            $permissionLevel <= 2  => 0.0,
            $permissionLevel <= 4  => 100.0,
            $permissionLevel <= 6  => 1000.0,
            $permissionLevel === 7 => 2000.0,
            default                => null,
        };
    }
}
