<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\Widgets;

use App\Domain\Account\Models\Account;
use Filament\Widgets\ChartWidget;

class AccountBalanceChart extends ChartWidget
{
    protected static ?string $heading = 'Account Balance Trends';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '30s';

    public ?string $filter = '7d';

    protected function getData(): array
    {
        $activeFilter = $this->filter;

        $data = $this->getBalanceData($activeFilter);

        return [
            'datasets' => [
                [
                    'label'           => 'Total Balance',
                    'data'            => $data->pluck('total'),
                    'borderColor'     => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                ],
                [
                    'label'           => 'Average Balance',
                    'data'            => $data->pluck('average'),
                    'borderColor'     => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                ],
            ],
            'labels' => $data->pluck('date'),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        return [
            '24h' => 'Last 24 Hours',
            '7d'  => 'Last 7 Days',
            '30d' => 'Last 30 Days',
            '90d' => 'Last 90 Days',
        ];
    }

    private function getBalanceData(string $period)
    {
        $endDate = now();
        $groupBy = match ($period) {
            '24h'   => ['hours' => 1, 'format' => 'H:00'],
            '7d'    => ['days' => 1, 'format' => 'M d'],
            '30d'   => ['days' => 1, 'format' => 'M d'],
            '90d'   => ['days' => 3, 'format' => 'M d'],
            default => ['days' => 1, 'format' => 'M d'],
        };

        $startDate = match ($period) {
            '24h'   => $endDate->copy()->subDay(),
            '7d'    => $endDate->copy()->subDays(7),
            '30d'   => $endDate->copy()->subDays(30),
            '90d'   => $endDate->copy()->subDays(90),
            default => $endDate->copy()->subDays(7),
        };

        // For demonstration, we'll generate sample trend data
        // In production, this would query historical balance snapshots
        $data = collect();
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $baseTotal = Account::where('created_at', '<=', $current)->sum('balance') / 100;
            $variance = rand(-5, 10) / 100; // Simulate balance changes

            $data->push(
                [
                    'date'    => $current->format($groupBy['format']),
                    'total'   => round($baseTotal * (1 + $variance), 2),
                    'average' => round(($baseTotal * (1 + $variance)) / max(Account::where('created_at', '<=', $current)->count(), 1), 2),
                ]
            );

            if ($period === '24h') {
                $current->addHours((int) ($groupBy['hours'] ?? 1));
            } else {
                $current->addDays((int) ($groupBy['days'] ?? 1));
            }
        }

        return $data;
    }
}
