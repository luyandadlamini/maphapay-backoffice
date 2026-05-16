<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Revenue;

use App\Domain\Pricing\Models\RevenueDailyRollup;
use App\Domain\Segments\Models\CustomerSegment;
use Filament\Widgets\ChartWidget;

class RevenueBySegmentWidget extends ChartWidget
{
    protected static ?string $heading = 'Revenue by Segment (30 days)';

    protected static ?int $sort = 2;

    protected static ?string $maxHeight = '360px';

    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $since = now()->subDays(30)->toDateString();

        $segmentNames = CustomerSegment::pluck('name', 'id');

        $rows = RevenueDailyRollup::where('date', '>=', $since)
            ->selectRaw('segment_id, SUM(gross_revenue_minor) as total')
            ->groupBy('segment_id')
            ->orderByDesc('total')
            ->get();

        $labels = $rows->map(
            fn ($r) => $r->segment_id
                ? ($segmentNames[$r->segment_id] ?? "Segment #{$r->segment_id}")
                : 'Unassigned'
        )->toArray();

        $data = $rows->map(fn ($r) => (int) $r->total)->toArray();

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

        return [
            'datasets' => [
                [
                    'data'            => $data,
                    'backgroundColor' => array_slice($palette, 0, count($data)),
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend'  => ['display' => true, 'position' => 'right'],
                'tooltip' => ['callbacks' => []],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
