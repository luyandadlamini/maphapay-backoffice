<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-shot repair: convert a stranded USD-only account balance into an SZL
 * balance so the dashboard and send-money flows can see it.
 *
 * Background: an account was provisioned via the Stripe card-issuing flow,
 * which credits USD into `account_balances`. SZL is the canonical user-facing
 * currency in this app, so the dashboard reads `getBalance('SZL')` and
 * send-money rejects with "Insufficient SZL balance". Until the Stripe
 * funding pipeline is taught to credit SZL natively (separate follow-up),
 * this command exists to unblock accounts created during testing.
 *
 * Intentionally NOT going through the event-sourced AssetTransactionAggregate
 * because this is a data repair, not a real money movement. The conversion
 * is logged for audit traceability.
 */
class BackfillSzlBalanceFromUsdCommand extends Command
{
    protected $signature = 'maphapay:backfill-szl-from-usd
                            {account_uuid : The account UUID to repair}
                            {--rate= : USD→SZL rate (defaults to config cards.processors.stripe.fx_rate_usd_szl)}
                            {--apply : Actually perform the change (default is dry-run)}';

    protected $description = 'Convert a stranded USD-only account balance into SZL for the dashboard';

    public function handle(): int
    {
        $accountUuid = (string) $this->argument('account_uuid');
        $rate = $this->option('rate') !== null
            ? (float) $this->option('rate')
            : (float) config('cards.processors.stripe.fx_rate_usd_szl', 18.50);
        $apply = (bool) $this->option('apply');

        $account = Account::where('uuid', $accountUuid)->first();
        if ($account === null) {
            $this->error("Account {$accountUuid} not found.");

            return self::FAILURE;
        }

        $usdRow = $account->getBalanceForAsset('USD');
        if ($usdRow === null || $usdRow->balance <= 0) {
            $this->warn("Account {$accountUuid} has no positive USD balance — nothing to backfill.");

            return self::SUCCESS;
        }
        $usdMinor = $usdRow->balance;

        $szlAsset = Asset::find('SZL');
        if ($szlAsset === null) {
            $this->error('SZL asset is not seeded.');

            return self::FAILURE;
        }

        $usdAsset = Asset::find('USD');
        $usdPrecision = $usdAsset !== null ? $usdAsset->precision : 2;
        $szlPrecision = $szlAsset->precision;

        $usdMajor = $usdMinor / (10 ** $usdPrecision);
        $szlMajor = $usdMajor * $rate;
        $szlMinor = (int) round($szlMajor * (10 ** $szlPrecision));

        $existingSzl = $account->getBalanceForAsset('SZL');
        $existingSzlMinor = $existingSzl !== null ? $existingSzl->balance : 0;
        $newSzlMinor = $existingSzlMinor + $szlMinor;

        $this->table(
            ['field', 'value'],
            [
                ['account_uuid', $accountUuid],
                ['usd_balance_minor', (string) $usdMinor],
                ['usd_balance_major', number_format($usdMajor, $usdPrecision, '.', '')],
                ['fx_rate_usd_szl', (string) $rate],
                ['szl_to_credit_minor', (string) $szlMinor],
                ['szl_to_credit_major', number_format($szlMajor, $szlPrecision, '.', '')],
                ['existing_szl_minor', (string) $existingSzlMinor],
                ['resulting_szl_minor', (string) $newSzlMinor],
                ['mode', $apply ? 'APPLY' : 'dry-run'],
            ]
        );

        if (! $apply) {
            $this->info('Dry run only. Re-run with --apply to perform the change.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Apply this change to account {$accountUuid}?", false)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($account, $newSzlMinor, $usdRow): void {
            AccountBalance::updateOrCreate(
                ['account_uuid' => $account->uuid, 'asset_code' => 'SZL'],
                ['balance' => $newSzlMinor]
            );
            $usdRow->update(['balance' => 0]);
        });

        Log::info('[maphapay:backfill-szl-from-usd] applied', [
            'account_uuid'  => $accountUuid,
            'usd_zeroed'    => $usdMinor,
            'szl_credited'  => $szlMinor,
            'fx_rate_used'  => $rate,
            'resulting_szl' => $newSzlMinor,
        ]);

        $this->info("Done. SZL balance is now {$newSzlMinor} minor units; USD zeroed.");

        return self::SUCCESS;
    }
}
