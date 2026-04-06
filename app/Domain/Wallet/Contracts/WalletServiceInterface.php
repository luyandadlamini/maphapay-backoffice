<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Contracts;

interface WalletServiceInterface
{
    /**
     * Deposit funds to an account for a specific asset.
     */
    public function deposit(mixed $accountUuid, string $assetCode, mixed $amount): void;

    /**
     * Withdraw funds from an account for a specific asset.
     */
    public function withdraw(mixed $accountUuid, string $assetCode, mixed $amount): void;

    /**
     * Transfer funds between accounts for a specific asset.
     */
    public function transfer(mixed $fromAccountUuid, mixed $toAccountUuid, string $assetCode, mixed $amount, ?string $reference = null): void;

    /**
     * Convert between different assets within the same account.
     */
    public function convert(mixed $accountUuid, string $fromAssetCode, string $toAssetCode, mixed $amount): void;
}
