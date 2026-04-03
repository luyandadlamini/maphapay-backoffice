<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Services;

use App\Domain\AuthorizedTransaction\Contracts\MoneyMovementRiskSignalProviderInterface;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\User;

class NullMoneyMovementRiskSignalProvider implements MoneyMovementRiskSignalProviderInterface
{
    public function evaluateInitiation(
        User $user,
        string $operationType,
        string $amount,
        string $assetCode,
        array $context = [],
    ): array {
        return [
            'step_up' => false,
            'reason' => null,
        ];
    }

    public function evaluatePreExecution(
        AuthorizedTransaction $transaction,
        array $context = [],
    ): array {
        return [
            'allow' => true,
            'reason' => null,
        ];
    }
}
