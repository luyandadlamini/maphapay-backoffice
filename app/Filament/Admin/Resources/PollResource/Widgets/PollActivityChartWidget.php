<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PollResource\Widgets;

use App\Domain\Governance\Models\Poll;
use App\Domain\Governance\Models\Vote;
use Filament\Widgets\ChartWidget;
use Stancl\Tenancy\Tenancy;

class PollActivityChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Governance Activity';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $labels = [];
        $pollsData = [];
        $votesData = [];

        // Get data for last 30 days
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $labels[] = $date->format('M j');

            if (! $this->hasActiveTenantContext()) {
                $pollsData[] = 0;
                $votesData[] = 0;

                continue;
            }

            $pollsData[] = Poll::whereDate('created_at', $date->toDateString())->count();
            $votesData[] = Vote::whereDate('voted_at', $date->toDateString())->count();
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Polls Created',
                    'data'            => $pollsData,
                    'borderColor'     => 'rgb(59, 130, 246)',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill'            => true,
                ],
                [
                    'label'           => 'Votes Cast',
                    'data'            => $votesData,
                    'borderColor'     => 'rgb(16, 185, 129)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill'            => true,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
        ];
    }

    private function hasActiveTenantContext(): bool
    {
        /** @var Tenancy $tenancy */
        $tenancy = app(Tenancy::class);

        return $tenancy->initialized;
    }
}
