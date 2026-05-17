<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\Widgets;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Models\Transaction;
use App\Domain\Account\Models\Turnover;
use App\Models\Tenant;
use App\Support\BankingDisplay;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Stancl\Tenancy\Tenancy;

class AccountStatsOverview extends BaseWidget
{
    public ?Model $record = null;

    protected function getStats(): array
    {
        if (! $this->record) {
            return $this->getDashboardStats();
        }

        return $this->getAccountStats();
    }

    /**
     * Aggregate stats across all tenants by iterating them one-by-one and
     * initializing tenancy per tenant so that UsesTenantConnection routes
     * queries to the correct per-tenant database.
     *
     * @return array<int, Stat>
     */
    private function getDashboardStats(): array
    {
        $totalAccounts = 0;
        $activeAccounts = 0;
        $frozenAccounts = 0;
        $totalBalanceMinor = 0;

        $defaultCurrency = config('banking.default_currency', 'SZL');
        $tenancy = app(Tenancy::class);

        // Snapshot the application connection default before we start iterating
        // tenants.  Stancl's DatabaseTenancyBootstrapper resets database.default
        // to 'central' on each end() call, so without this restore any code that
        // runs after the widget (session writes, notifications, etc.) would query
        // the wrong connection.
        $originalDefault = config('database.default');

        try {
            Tenant::on('central')->lazy(100)->each(
                function (Tenant $tenant) use (
                    &$totalAccounts,
                    &$activeAccounts,
                    &$frozenAccounts,
                    &$totalBalanceMinor,
                    $defaultCurrency,
                    $tenancy,
                ): void {
                    $tenancy->initialize($tenant);

                    try {
                        $totalAccounts += Account::count();
                        $activeAccounts += Account::where('frozen', false)->count();
                        $frozenAccounts += Account::where('frozen', true)->count();
                        $totalBalanceMinor += (int) AccountBalance::query()
                            ->where('asset_code', $defaultCurrency)
                            ->sum('balance');
                    } catch (QueryException) {
                        // Tenant database does not exist or is unreachable — skip.
                    } finally {
                        $tenancy->end();
                    }
                }
            );
        } finally {
            app('db')->setDefaultConnection($originalDefault);
            config(['database.default' => $originalDefault]);
        }

        return [
            Stat::make('Total Accounts', number_format($totalAccounts))
                ->description($activeAccounts . ' active, ' . $frozenAccounts . ' frozen')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
            Stat::make('Total Balance', BankingDisplay::minorUnitsAsString($totalBalanceMinor))
                ->description('Across all accounts')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),
            Stat::make('Average Balance', BankingDisplay::minorUnitsAsString($totalAccounts > 0 ? $totalBalanceMinor / $totalAccounts : 0))
                ->description('Per account')
                ->descriptionIcon('heroicon-m-calculator')
                ->color('info'),
            Stat::make('Frozen Accounts', $frozenAccounts)
                ->description(number_format($totalAccounts > 0 ? ($frozenAccounts / $totalAccounts) * 100 : 0, 1) . '% of total')
                ->descriptionIcon($frozenAccounts > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($frozenAccounts > 0 ? 'danger' : 'success'),
        ];
    }

    /**
     * Stats for a single account record (shown on the ViewAccount page).
     *
     * @return array<int, Stat>
     */
    private function getAccountStats(): array
    {
        $account = $this->record;

        if (! $account instanceof Account) {
            return [];
        }

        $lastTransaction = Transaction::where('account_uuid', $account->uuid)
            ->latest()
            ->first();

        $monthlyTurnover = Turnover::where('account_uuid', $account->uuid)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->first();

        $totalTransactions = Transaction::where('account_uuid', $account->uuid)->count();

        return [
            Stat::make('Current Balance', BankingDisplay::minorUnitsAsString($account->balance))
                ->description($account->frozen ? 'Account Frozen' : 'Account Active')
                ->descriptionIcon($account->frozen ? 'heroicon-m-lock-closed' : 'heroicon-m-lock-open')
                ->color($account->frozen ? 'danger' : 'success'),
            Stat::make('Total Transactions', number_format($totalTransactions))
                ->description($lastTransaction ? 'Last: ' . Carbon::parse($lastTransaction->created_at)->diffForHumans() : 'No transactions')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info'),
            Stat::make('Monthly Credit', BankingDisplay::minorUnitsAsString($monthlyTurnover?->credit ?? 0))
                ->description('This month')
                ->descriptionIcon('heroicon-m-arrow-down-tray')
                ->color('success'),
            Stat::make('Monthly Debit', BankingDisplay::minorUnitsAsString($monthlyTurnover?->debit ?? 0))
                ->description('This month')
                ->descriptionIcon('heroicon-m-arrow-up-tray')
                ->color('warning'),
        ];
    }
}
