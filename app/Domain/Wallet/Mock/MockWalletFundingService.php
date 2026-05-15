<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Mock;

final class MockWalletFundingService
{
    public function __construct(
        private readonly MockWalletStore $store,
    ) {
    }

    /**
     * @return array{account_ref: string, balance_minor: int, currency: string}
     */
    public function fund(string $providerId, string $accountRef, int $amountMinor, string $currency): array
    {
        $this->store->accountOrSeed($providerId, $accountRef);
        $this->store->setBalance($providerId, $accountRef, $this->store->getBalance($providerId, $accountRef), $currency);

        return [
            'account_ref'   => $accountRef,
            'balance_minor' => $this->store->creditAccount($providerId, $accountRef, $amountMinor),
            'currency'      => $currency,
        ];
    }

    /**
     * @return array{account_ref: string, balance_minor: int, currency: string}
     */
    public function setBalance(string $providerId, string $accountRef, int $amountMinor, string $currency): array
    {
        return [
            'account_ref'   => $accountRef,
            'balance_minor' => $this->store->setBalance($providerId, $accountRef, $amountMinor, $currency),
            'currency'      => $currency,
        ];
    }

    /**
     * @return array{account_ref: string, balance_minor: int, currency: string, recent: array<int, array<string, mixed>>}
     */
    public function getBalance(string $providerId, string $accountRef): array
    {
        $account = $this->store->accountOrSeed($providerId, $accountRef);

        return [
            'account_ref'   => $accountRef,
            'balance_minor' => (int) $account['balance_minor'],
            'currency'      => (string) $account['currency'],
            'recent'        => $this->store->recentHistory($providerId, $accountRef),
        ];
    }
}
