<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AccountResource\Widgets;

use App\Domain\Account\Models\Transaction as TransactionEvent;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class RecentTransactionsChart extends ChartWidget
{
    protected static ?string $heading = 'Transaction Volume';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '30s';

    public ?string $filter = '7d';

    protected function getData(): array
    {
        $activeFilter = $this->filter;

        $data = $this->getTransactionData($activeFilter);

        return [
            'datasets' => [
                [
                    'label'           => 'Deposits',
                    'data'            => $data->pluck('deposits'),
                    'backgroundColor' => '#10b981',
                    'borderColor'     => '#10b981',
                ],
                [
                    'label'           => 'Withdrawals',
                    'data'            => $data->pluck('withdrawals'),
                    'backgroundColor' => '#ef4444',
                    'borderColor'     => '#ef4444',
                ],
                [
                    'label'           => 'Transfers',
                    'data'            => $data->pluck('transfers'),
                    'backgroundColor' => '#3b82f6',
                    'borderColor'     => '#3b82f6',
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
                    'stacked'     => false,
                ],
                'x' => [
                    'stacked' => false,
                ],
            ],
        ];
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

    private function getTransactionData(string $period)
    {
        $endDate = now();

        $startDate = match ($period) {
            '24h'   => $endDate->copy()->subDay(),
            '7d'    => $endDate->copy()->subDays(7),
            '30d'   => $endDate->copy()->subDays(30),
            '90d'   => $endDate->copy()->subDays(90),
            default => $endDate->copy()->subDays(7),
        };

        $groupBy = match ($period) {
            '24h' => config('database.default') === 'mysql'
                ? "DATE_FORMAT(created_at, '%H:00')"
                : "strftime('%H:00', created_at)",
            default => config('database.default') === 'mysql'
                ? 'DATE(created_at)'
                : 'date(created_at)',
        };

        // Query stored events for transaction data
        $transactions = TransactionEvent::select(
            DB::raw($groupBy . ' as date'),
            DB::raw("COUNT(CASE WHEN event_class LIKE '%MoneyAdded' THEN 1 END) as deposits"),
            DB::raw("COUNT(CASE WHEN event_class LIKE '%MoneySubtracted' THEN 1 END) as withdrawals"),
            DB::raw("COUNT(CASE WHEN event_class LIKE '%MoneyTransferred' THEN 1 END) as transfers")
        )
            ->whereIn(
                'event_class',
                [
                    'App\\Domain\\Account\\Events\\MoneyAdded',
                    'App\\Domain\\Account\\Events\\MoneySubtracted',
                    'App\\Domain\\Account\\Events\\MoneyTransferred',
                ]
            )
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Fill in missing dates with zeros
        $data = collect();
        $current = $startDate->copy();
        $format = $period === '24h' ? 'H:00' : 'Y-m-d';

        while ($current <= $endDate) {
            $dateKey = $current->format($format);
            $transaction = $transactions->firstWhere('date', $dateKey);

            $data->push(
                [
                    'date'        => $period === '24h' ? $dateKey : $current->format('M d'),
                    'deposits'    => $transaction?->deposits ?? 0,
                    'withdrawals' => $transaction?->withdrawals ?? 0,
                    'transfers'   => $transaction?->transfers ?? 0,
                ]
            );

            if ($period === '24h') {
                $current->addHour();
            } else {
                $current->addDay();
            }
        }

        return $data;
    }
}
