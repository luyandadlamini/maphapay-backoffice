<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Exceptions\MockNotAvailableException;
use App\Domain\Wallet\Services\Results\BalanceResult;
use App\Domain\Wallet\Services\Results\FundResult;
use Illuminate\Support\Facades\Http;

final class MockWalletAdminClient
{
    public function fund(string $providerPath, string $accountRef, int $amountMinor, ?string $note): FundResult
    {
        $this->guardEnabled();

        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->post("/{$providerPath}/_admin/fund", [
                'account_ref' => $accountRef,
                'amount'      => $amountMinor,
                'currency'    => 'SZL',
                'note'        => $note,
            ])
            ->throw()
            ->json();

        return new FundResult(
            balanceMinor: (int) ($response['balance_minor'] ?? 0),
            currency: (string) ($response['currency'] ?? 'SZL'),
        );
    }

    public function balance(string $providerPath, string $accountRef): BalanceResult
    {
        $this->guardEnabled();

        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->get("/{$providerPath}/_admin/balance/{$accountRef}")
            ->throw()
            ->json();

        return new BalanceResult(
            balanceMinor: (int) ($response['balance_minor'] ?? 0),
            currency: (string) ($response['currency'] ?? 'SZL'),
            lastUpdated: $response['last_updated'] ?? null,
        );
    }

    private function baseUrl(): string
    {
        return (string) (config('services.mock_wallets.base_url') ?? url('/__mock/wallets'));
    }

    private function guardEnabled(): void
    {
        if (! config('services.mock_wallets.enabled')) {
            throw MockNotAvailableException::disabled();
        }
    }
}
