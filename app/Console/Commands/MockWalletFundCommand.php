<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Asset\Models\Asset;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Mock\MockWalletFundingService;
use Illuminate\Console\Command;

final class MockWalletFundCommand extends Command
{
    protected $signature = 'mock-wallet:fund {provider} {account_ref} {amount} {--currency=SZL} {--reset}';

    protected $description = 'Fund a mock external wallet account.';

    public function handle(MockWalletFundingService $funding): int
    {
        if (! (bool) config('wallet_mocks.enabled') || app()->environment('production')) {
            $this->error('Wallet mocks are disabled.');

            return self::FAILURE;
        }

        $providerId = (string) $this->argument('provider');
        $accountRef = (string) $this->argument('account_ref');
        $amount = (string) $this->argument('amount');
        $currency = strtoupper((string) $this->option('currency'));
        $precision = $this->precisionFor($currency);
        $amountMinor = MoneyConverter::toSmallestUnit($amount, $precision);

        $result = (bool) $this->option('reset')
            ? $funding->setBalance($providerId, $accountRef, $amountMinor, $currency)
            : $funding->fund($providerId, $accountRef, $amountMinor, $currency);

        $this->info("{$providerId} {$accountRef}: balance now {$result['balance_minor']} ({$result['currency']})");

        return self::SUCCESS;
    }

    private function precisionFor(string $currency): int
    {
        $asset = Asset::query()->where('code', $currency)->first();

        return $asset instanceof Asset ? $asset->precision : 2;
    }
}
