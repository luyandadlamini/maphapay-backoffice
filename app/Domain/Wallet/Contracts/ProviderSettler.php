<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Contracts;

interface ProviderSettler
{
    public function providerId(): string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function settle(string $providerRequestId, string $outcome, array $payload): void;
}
