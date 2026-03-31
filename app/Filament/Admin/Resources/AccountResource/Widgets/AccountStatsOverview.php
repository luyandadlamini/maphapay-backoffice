<?php

namespace App\Filament\Admin\Resources\AccountResource\Widgets;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Models\Transaction;
use App\Domain\Account\Models\Turnover;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

class AccountStatsOverview extends BaseWidget
{
    public ?Model $record = null;

    protected function getStats(): array
    {
        if (! $this->record) {
            // Dashboard stats for all accounts
            $totalAccounts = Account::count();
            $activeAccounts = Account::where('frozen', false)->count();
            $frozenAccounts = Account::where('frozen', true)->count();
            $totalBalance = AccountBalance::query()
                ->where('asset_code', config('banking.default_currency', 'SZL'))
                ->sum('balance');

            return [
                Stat::make('Total Accounts', number_format($totalAccounts))
                    ->description($activeAccounts . ' active, ' . $frozenAccounts . ' frozen')
                    ->descriptionIcon('heroicon-m-arrow-trending-up')
                    ->color('success'),
                Stat::make('Total Balance', '$' . number_format($totalBalance / 100, 2))
                    ->description('Across all accounts')
                    ->descriptionIcon('heroicon-m-banknotes')
                    ->color('primary'),
                Stat::make('Average Balance', '$' . number_format($totalAccounts > 0 ? ($totalBalance / 100) / $totalAccounts : 0, 2))
                    ->description('Per account')
                    ->descriptionIcon('heroicon-m-calculator')
                    ->color('info'),
                Stat::make('Frozen Accounts', $frozenAccounts)
                    ->description(number_format($totalAccounts > 0 ? ($frozenAccounts / $totalAccounts) * 100 : 0, 1) . '% of total')
                    ->descriptionIcon($frozenAccounts > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                    ->color($frozenAccounts > 0 ? 'danger' : 'success'),
            ];
        }

        // Individual account stats
        $account = $this->record;

        $lastTransaction = Transaction::where('account_uuid', $account->uuid)
            ->latest()
            ->first();

        $monthlyTurnover = Turnover::where('account_uuid', $account->uuid)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->first();

        $totalTransactions = Transaction::where('account_uuid', $account->uuid)->count();

        return [
            Stat::make('Current Balance', '$' . number_format($account->balance / 100, 2))
                ->description($account->frozen ? 'Account Frozen' : 'Account Active')
                ->descriptionIcon($account->frozen ? 'heroicon-m-lock-closed' : 'heroicon-m-lock-open')
                ->color($account->frozen ? 'danger' : 'success'),
            Stat::make('Total Transactions', number_format($totalTransactions))
                ->description($lastTransaction ? 'Last: ' . \Carbon\Carbon::parse($lastTransaction->created_at)->diffForHumans() : 'No transactions')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info'),
            Stat::make('Monthly Credit', '$' . number_format(($monthlyTurnover?->credit ?? 0) / 100, 2))
                ->description('This month')
                ->descriptionIcon('heroicon-m-arrow-down-tray')
                ->color('success'),
            Stat::make('Monthly Debit', '$' . number_format(($monthlyTurnover?->debit ?? 0) / 100, 2))
                ->description('This month')
                ->descriptionIcon('heroicon-m-arrow-up-tray')
                ->color('warning'),
        ];
    }
}
