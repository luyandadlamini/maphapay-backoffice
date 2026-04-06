<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\Widgets;

use App\Domain\Account\Models\Turnover;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class TurnoverTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Turnover Flow Analysis';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    public ?string $filter = '6m';

    protected function getData(): array
    {
        $activeFilter = $this->filter;

        $data = $this->getTurnoverData($activeFilter);

        return [
            'datasets' => [
                [
                    'label'           => 'Debit (Outflows)',
                    'data'            => $data->pluck('debit'),
                    'backgroundColor' => 'rgba(239, 68, 68, 0.8)',
                    'borderColor'     => '#ef4444',
                    'borderWidth'     => 2,
                ],
                [
                    'label'           => 'Credit (Inflows)',
                    'data'            => $data->pluck('credit'),
                    'backgroundColor' => 'rgba(16, 185, 129, 0.8)',
                    'borderColor'     => '#10b981',
                    'borderWidth'     => 2,
                ],
                [
                    'label'           => 'Net Flow',
                    'data'            => $data->pluck('net'),
                    'type'            => 'line',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.2)',
                    'borderColor'     => '#3b82f6',
                    'borderWidth'     => 3,
                    'tension'         => 0.4,
                    'fill'            => true,
                ],
            ],
            'labels' => $data->pluck('month'),
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
                    'ticks'       => [
                        'callback' => "function(value) { return '$' + value.toLocaleString(); }",
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display'  => true,
                    'position' => 'top',
                ],
                'tooltip' => [
                    'callbacks' => [
                        'label' => "function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += '$' + context.parsed.y.toLocaleString();
                            }
                            return label;
                        }",
                    ],
                ],
            ],
        ];
    }

    protected function getFilters(): ?array
    {
        return [
            '3m'  => 'Last 3 Months',
            '6m'  => 'Last 6 Months',
            '12m' => 'Last 12 Months',
            '24m' => 'Last 24 Months',
        ];
    }

    private function getTurnoverData(string $period)
    {
        $months = match ($period) {
            '3m'    => 3,
            '6m'    => 6,
            '12m'   => 12,
            '24m'   => 24,
            default => 6,
        };

        $endDate = now()->endOfMonth();
        $startDate = now()->subMonths($months - 1)->startOfMonth();

        $turnovers = Turnover::select(
            'year',
            'month',
            DB::raw('SUM(debit) as total_debit'),
            DB::raw('SUM(credit) as total_credit')
        )
            ->where(
                function ($query) use ($startDate) {
                    $query->where('year', '>', $startDate->year)
                        ->orWhere(
                            function ($q) use ($startDate) {
                                $q->where('year', $startDate->year)
                                    ->where('month', '>=', $startDate->month);
                            }
                        );
                }
            )
            ->where(
                function ($query) use ($endDate) {
                    $query->where('year', '<', $endDate->year)
                        ->orWhere(
                            function ($q) use ($endDate) {
                                $q->where('year', $endDate->year)
                                    ->where('month', '<=', $endDate->month);
                            }
                        );
                }
            )
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        // Fill in missing months with zeros
        $data = collect();
        $current = $startDate->copy();

        while ($current <= $endDate) {
            $turnover = $turnovers->first(
                function ($t) use ($current) {
                    return $t->year == $current->year && $t->month == $current->month;
                }
            );

            $debit = ($turnover?->total_debit ?? 0) / 100;
            $credit = ($turnover?->total_credit ?? 0) / 100;

            $data->push(
                [
                    'month'  => $current->format('M Y'),
                    'debit'  => round($debit, 2),
                    'credit' => round($credit, 2),
                    'net'    => round($credit - $debit, 2),
                ]
            );

            $current->addMonth();
        }

        return $data;
    }
}
