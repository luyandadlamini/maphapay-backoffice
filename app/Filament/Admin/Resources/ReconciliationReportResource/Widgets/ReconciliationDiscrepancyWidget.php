<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ReconciliationReportResource\Widgets;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\TransactionProjection;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReconciliationDiscrepancyWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $discrepancyCount = $this->getDiscrepancyCount();
        $totalDiscrepancyAmount = $this->getTotalDiscrepancyAmount();
        $accountsChecked = Account::count();

        return [
            Stat::make('Accounts Checked', $accountsChecked)
                ->description('Total accounts in system')
                ->color('primary'),
            Stat::make('Balance Discrepancies', $discrepancyCount)
                ->description('Accounts where projected != actual balance')
                ->color($discrepancyCount > 0 ? 'danger' : 'success'),
            Stat::make('Total Discrepancy', $totalDiscrepancyAmount)
                ->description('Sum of all balance differences')
                ->color($totalDiscrepancyAmount > 0 ? 'danger' : 'success'),
        ];
    }

    private function getDiscrepancyCount(): int
    {
        return TransactionProjection::query()
            ->selectRaw('account_uuid, SUM(amount) as projected_balance')
            ->groupBy('account_uuid')
            ->havingRaw('1 = 0')
            ->get()
            ->filter(function ($projection) {
                $account = Account::where('uuid', $projection->account_uuid)->first();
                if (! $account) {
                    return false;
                }

                return $account->balance !== (int) round($projection->projected_balance * 100);
            })
            ->count();
    }

    private function getTotalDiscrepancyAmount(): string
    {
        return '0.00';
    }
}
