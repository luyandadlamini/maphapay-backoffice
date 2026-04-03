<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Contracts;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\User;

interface MoneyMovementRiskSignalProviderInterface
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
    ): array;

    /**
     * @param  array<string, mixed>  $context
     * @return array{allow: bool, reason: string|null}
     */
    public function evaluatePreExecution(
        AuthorizedTransaction $transaction,
        array $context = [],
    ): array;
}
