<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Revenue;

use App\Domain\Pricing\Models\RevenueDailyRollup;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class RevenueByCategoryWidget extends ChartWidget
{
    protected static ?string $heading = 'Revenue by Product (90 days)';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $maxHeight = '420px';

    protected function getData(): array
    {
        $since = now()->subDays(90)->toDateString();

        $rows = RevenueDailyRollup::where('date', '>=', $since)
            ->selectRaw('date, product_code, SUM(gross_revenue_minor) as total')
            ->groupBy('date', 'product_code')
            ->orderBy('date')
            ->get();

        $dateStrings = $rows->pluck('date')
            ->map(fn ($d) => $d instanceof Carbon ? $d->toDateString() : (string) $d)
            ->unique()
            ->sort()
            ->values();

        $labels = $dateStrings->map(fn (string $d) => Carbon::parse($d)->format('M j'))->toArray();

        $products = $rows->pluck('product_code')->unique()->sort()->values();

        $palette = [
            'rgba(99,102,241,0.8)',
            'rgba(34,197,94,0.8)',
            'rgba(251,146,60,0.8)',
            'rgba(236,72,153,0.8)',
            'rgba(20,184,166,0.8)',
            'rgba(234,179,8,0.8)',
            'rgba(239,68,68,0.8)',
            'rgba(168,85,247,0.8)',
        ];

        $datasets = $products->values()->map(function (string $code, int $i) use ($rows, $dateStrings, $palette): array {
            $byDate = $rows->where('product_code', $code)
                ->keyBy(fn ($r) => $r->date instanceof Carbon ? $r->date->toDateString() : (string) $r->date);

            return [
                'label'           => $code,
                'data'            => $dateStrings->map(fn (string $d) => (int) ($byDate->get($d)->total ?? 0))->toArray(),
                'backgroundColor' => $palette[$i % count($palette)],
                'stack'           => 'revenue',
            ];
        })->toArray();

        return [
            'datasets' => $datasets,
            'labels'   => $labels,
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
                'x' => ['stacked' => true],
                'y' => [
                    'stacked' => true,
                    'title'   => ['display' => true, 'text' => 'Revenue (minor units)'],
                ],
            ],
            'interaction' => ['mode' => 'nearest', 'axis' => 'x', 'intersect' => false],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
