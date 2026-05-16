<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY diagnostic: dump everything relevant to a user's dashboard balance.
 *
 * Used to investigate the "admin shows balance, mobile shows 0.00" discrepancy.
 * Writes nothing. Safe to run on any environment.
 */
class DiagnoseUserBalanceCommand extends Command
{
    protected $signature = 'maphapay:diagnose-balance
                            {identifier : User email, mobile, uuid, or account uuid}';

    protected $description = 'Read-only dump of user/account/balance state for dashboard debugging';

    public function handle(): int
    {
        $id = (string) $this->argument('identifier');

        $user = User::where('email', $id)
            ->orWhere('mobile', $id)
            ->orWhere('uuid', $id)
            ->first();

        if ($user === null) {
            $account = Account::where('uuid', $id)->first();
            if ($account === null) {
                $this->error("No user (email/mobile/uuid) or account (uuid) found for '{$id}'.");

                return self::FAILURE;
            }
            $user = User::where('uuid', $account->user_uuid)->first();
        }

        if ($user === null) {
            $this->error('Resolved an account but its user_uuid does not exist.');

            return self::FAILURE;
        }

        $this->info('=== USER ===');
        $this->table(['field', 'value'], [
            ['id', (string) $user->id],
            ['uuid', $user->uuid],
            ['email', (string) $user->email],
            ['mobile', (string) $user->mobile],
        ]);

        $accounts = Account::where('user_uuid', $user->uuid)->orderBy('id')->get();
        $this->info("\n=== ACCOUNTS (count: {$accounts->count()}) ===");
        if ($accounts->isEmpty()) {
            $this->warn('No accounts for this user.');

            return self::SUCCESS;
        }

        $rows = $accounts->map(fn (Account $a) => [
            'id'              => (string) $a->id,
            'uuid'            => $a->uuid,
            'name'            => (string) $a->name,
            'account_number'  => (string) $a->account_number,
            'raw_balance_col' => (string) DB::table('accounts')->where('uuid', $a->uuid)->value('balance'),
            'frozen'          => $a->frozen ? 'yes' : 'no',
            'created_at'      => (string) $a->created_at,
        ])->all();
        $this->table(array_keys($rows[0]), $rows);

        $accountUuids = $accounts->pluck('uuid')->all();
        $balances = AccountBalance::whereIn('account_uuid', $accountUuids)->orderBy('account_uuid')->orderBy('asset_code')->get();
        $this->info("\n=== ACCOUNT_BALANCES (count: {$balances->count()}) ===");
        if ($balances->isEmpty()) {
            $this->warn('No account_balances rows for any of these accounts.');
        } else {
            $brows = $balances->map(fn (AccountBalance $b) => [
                'account_uuid'  => $b->account_uuid,
                'asset_code'    => $b->asset_code,
                'balance_minor' => (string) $b->balance,
                'updated_at'    => (string) $b->updated_at,
            ])->all();
            $this->table(array_keys($brows[0]), $brows);
        }

        $this->info("\n=== DASHBOARD CONTROLLER SIMULATION ===");
        $firstAccount = Account::where('user_uuid', $user->uuid)->first();
        $firstBalanceSzlMinor = $firstAccount !== null ? $firstAccount->getBalance('SZL') : 0;
        $totalSzlMinor = AccountBalance::whereIn('account_uuid', $accountUuids)
            ->where('asset_code', 'SZL')
            ->sum('balance');
        $totalUsdMinor = AccountBalance::whereIn('account_uuid', $accountUuids)
            ->where('asset_code', 'USD')
            ->sum('balance');

        $this->table(['field', 'value'], [
            ['Account::where(user_uuid)->first()->uuid', $firstAccount !== null ? $firstAccount->uuid : '(null)'],
            ['that account getBalance(SZL) minor', (string) $firstBalanceSzlMinor],
            ['that account getBalance(SZL) major', number_format($firstBalanceSzlMinor / 100, 2, '.', '')],
            ['SUM SZL across ALL user accounts minor', (string) $totalSzlMinor],
            ['SUM SZL across ALL user accounts major', number_format(((int) $totalSzlMinor) / 100, 2, '.', '')],
            ['SUM USD across ALL user accounts minor', (string) $totalUsdMinor],
            ['SUM USD across ALL user accounts major', number_format(((int) $totalUsdMinor) / 100, 2, '.', '')],
        ]);

        return self::SUCCESS;
    }
}
