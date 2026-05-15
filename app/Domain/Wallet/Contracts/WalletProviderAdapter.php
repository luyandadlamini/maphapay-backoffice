<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Contracts;

interface WalletProviderAdapter
{
    public function providerId(): string;

    public function link(string $identifier, string $currency): WalletLinkResult;

    public function collect(WalletMovementRequest $req): WalletMovementResult;

    public function disburse(WalletMovementRequest $req): WalletMovementResult;

    public function status(string $providerRequestId): WalletMovementStatus;

    /**
     * @param  array<string, mixed>  $headers
     */
    public function verifyWebhookSignature(string $rawBody, array $headers): bool;
}
