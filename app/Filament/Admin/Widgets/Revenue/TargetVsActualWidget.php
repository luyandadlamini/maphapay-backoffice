<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Revenue;

use App\Domain\Analytics\Models\RevenueTarget;
use App\Domain\Pricing\Models\RevenueDailyRollup;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class TargetVsActualWidget extends ChartWidget
{
    protected static ?string $heading = 'Target vs Actual Revenue (3 months)';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected static ?string $maxHeight = '360px';

    protected function getData(): array
    {
        $months = collect([
            now()->subMonths(2)->startOfMonth(),
            now()->subMonths(1)->startOfMonth(),
            now()->startOfMonth(),
        ]);

        /** @var Carbon $earliest */
        $earliest = $months->first() ?? now()->subMonths(2)->startOfMonth();

        $monthKeys = $months->map(fn (Carbon $m) => $m->format('Y-m'));

        // Actual: sum gross_revenue_minor per month, convert minor→major (/100)
        // toBase() drops to the query builder to avoid Eloquent's column-type checking on aliases.
        $actuals = RevenueDailyRollup::where('date', '>=', $earliest->toDateString())
            ->selectRaw("DATE_FORMAT(`date`, '%Y-%m') as `month`, SUM(gross_revenue_minor) as total")
            ->groupByRaw("DATE_FORMAT(`date`, '%Y-%m')")
            ->toBase()
            ->pluck('total', 'month')
            ->map(fn ($v) => round((float) $v / 100, 2));

        // Target: sum amount per YYYY-MM key (amount is already major-unit decimal)
        /** @var array<int, string> $monthKeyArray */
        $monthKeyArray = $monthKeys->toArray();

        $targets = RevenueTarget::whereIn('period_month', $monthKeyArray)
            ->selectRaw('period_month as `month`, SUM(amount) as total')
            ->groupBy('period_month')
            ->toBase()
            ->pluck('total', 'month')
            ->map(fn ($v) => round((float) $v, 2));

        $labels = $monthKeys->map(fn (string $k) => Carbon::parse($k . '-01')->format('M Y'))->toArray();
        $actualData = $monthKeys->map(fn (string $k) => (float) ($actuals[$k] ?? 0))->toArray();
        $targetData = $monthKeys->map(fn (string $k) => (float) ($targets[$k] ?? 0))->toArray();

        return [
            'datasets' => [
                [
                    'label'           => 'Actual',
                    'data'            => $actualData,
                    'borderColor'     => 'rgb(34,197,94)',
                    'backgroundColor' => 'rgba(34,197,94,0.1)',
                    'tension'         => 0.3,
                    'fill'            => true,
                ],
                [
                    'label'           => 'Target',
                    'data'            => $targetData,
                    'borderColor'     => 'rgb(251,146,60)',
                    'backgroundColor' => 'rgba(251,146,60,0.1)',
                    'tension'         => 0.3,
                    'borderDash'      => [6, 3],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend'  => ['display' => true, 'position' => 'top'],
                'tooltip' => ['mode' => 'index', 'intersect' => false],
            ],
            'scales' => [
                'y' => [
                    'title' => ['display' => true, 'text' => 'Revenue (major units)'],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
