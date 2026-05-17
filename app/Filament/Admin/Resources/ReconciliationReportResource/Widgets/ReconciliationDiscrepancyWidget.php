<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ReconciliationReportResource\Widgets;

use App\Domain\Account\Models\AccountMembership;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReconciliationDiscrepancyWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $discrepancyCount = $this->getDiscrepancyCount();
        $totalDiscrepancyAmount = $this->getTotalDiscrepancyAmount();
        $accountsChecked = AccountMembership::count();

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
        // Stub — full reconciliation logic pending tenant-aware implementation.
        return 0;
    }

    private function getTotalDiscrepancyAmount(): string
    {
        return '0.00';
    }
}
