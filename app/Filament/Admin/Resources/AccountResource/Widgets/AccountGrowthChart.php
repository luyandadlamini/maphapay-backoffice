<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\Widgets;

use App\Domain\Account\Models\Account;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class AccountGrowthChart extends ChartWidget
{
    protected static ?string $heading = 'Account Growth';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = '1';

    protected static ?string $pollingInterval = '60s';

    public ?string $filter = '30d';

    protected function getData(): array
    {
        $activeFilter = $this->filter;

        $data = $this->getGrowthData($activeFilter);

        return [
            'datasets' => [
                [
                    'label'           => 'New Accounts',
                    'data'            => $data->pluck('new'),
                    'backgroundColor' => 'rgba(34, 197, 94, 0.8)',
                    'borderColor'     => '#22c55e',
                    'borderWidth'     => 2,
                ],
                [
                    'label'           => 'Cumulative Total',
                    'data'            => $data->pluck('cumulative'),
                    'type'            => 'line',
                    'backgroundColor' => 'rgba(99, 102, 241, 0.2)',
                    'borderColor'     => '#6366f1',
                    'borderWidth'     => 3,
                    'tension'         => 0.4,
                    'yAxisID'         => 'y1',
                ],
            ],
            'labels' => $data->pluck('date'),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'position'    => 'left',
                    'title'       => [
                        'display' => true,
                        'text'    => 'New Accounts',
                    ],
                ],
                'y1' => [
                    'beginAtZero' => true,
                    'position'    => 'right',
                    'title'       => [
                        'display' => true,
                        'text'    => 'Total Accounts',
                    ],
                    'grid' => [
                        'drawOnChartArea' => false,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display'  => true,
                    'position' => 'top',
                ],
            ],
        ];
    }

    protected function getFilters(): ?array
    {
        return [
            '7d'   => 'Last 7 Days',
            '30d'  => 'Last 30 Days',
            '90d'  => 'Last 90 Days',
            '365d' => 'Last Year',
        ];
    }

    private function getGrowthData(string $period)
    {
        $endDate = now();

        $startDate = match ($period) {
            '7d'    => $endDate->copy()->subDays(7),
            '30d'   => $endDate->copy()->subDays(30),
            '90d'   => $endDate->copy()->subDays(90),
            '365d'  => $endDate->copy()->subDays(365),
            default => $endDate->copy()->subDays(30),
        };

        $groupBy = match ($period) {
            '7d'    => ['interval' => 'day', 'format' => 'M d'],
            '30d'   => ['interval' => 'day', 'format' => 'M d'],
            '90d'   => ['interval' => 'week', 'format' => 'M d'],
            '365d'  => ['interval' => 'month', 'format' => 'M Y'],
            default => ['interval' => 'day', 'format' => 'M d'],
        };

        $dateFormat = match ($groupBy['interval']) {
            'day'   => '%Y-%m-%d',
            'week'  => '%Y-%W',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $dateExpression = config('database.default') === 'mysql'
            ? "DATE_FORMAT(created_at, '{$dateFormat}')"
            : "strftime('{$dateFormat}', created_at)";

        $accounts = Account::select(
            DB::raw("{$dateExpression} as period"),
            DB::raw('COUNT(*) as count'),
            DB::raw('MIN(created_at) as period_start')
        )
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate)
            ->groupBy('period')
            ->orderBy('period_start')
            ->get();

        $totalBefore = Account::where('created_at', '<', $startDate)->count();

        // Fill in missing periods with zeros
        $data = collect();
        $cumulative = $totalBefore;
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $periodKey = $current->format(
                match ($groupBy['interval']) {
                    'day'   => 'Y-m-d',
                    'week'  => 'Y-W',
                    'month' => 'Y-m',
                    default => 'Y-m-d',
                }
            );

            $account = $accounts->firstWhere('period', $periodKey);
            $new = $account?->count ?? 0;
            $cumulative += $new;

            $data->push(
                [
                    'date'       => $current->format($groupBy['format']),
                    'new'        => $new,
                    'cumulative' => $cumulative,
                ]
            );

            match ($groupBy['interval']) {
                'day'   => $current->addDay(),
                'week'  => $current->addWeek(),
                'month' => $current->addMonth(),
                default => $current->addDay(),
            };
        }

        return $data;
    }
}
