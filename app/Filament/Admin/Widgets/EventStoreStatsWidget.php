<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\Monitoring\Services\EventStoreService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventStoreStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $data = Cache::remember('event_store_stats_widget', 30, function () {
            return $this->computeStats();
        });

        return $this->buildStats($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function computeStats(): array
    {
        $defaults = [
            'total_events'     => 0,
            'total_aggregates' => 0,
            'total_snapshots'  => 0,
            'events_today'     => 0,
            'domain_count'     => 0,
        ];

        try {
            $service = app(EventStoreService::class);
            $allStats = $service->getAllStats();

            return $allStats['summary'] ?? $defaults;
        } catch (Throwable $e) {
            Log::warning('EventStoreStatsWidget: failed to compute stats', ['error' => $e->getMessage()]);

            return $defaults;
        }
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<Stat>
     */
    private function buildStats(array $data): array
    {
        $totalEvents = (int) ($data['total_events'] ?? 0);
        $totalAggregates = (int) ($data['total_aggregates'] ?? 0);
        $totalSnapshots = (int) ($data['total_snapshots'] ?? 0);
        $eventsToday = (int) ($data['events_today'] ?? 0);

        return [
            Stat::make('Total Events', number_format($totalEvents))
                ->description('Across all event tables')
                ->descriptionIcon('heroicon-m-circle-stack')
                ->color('primary'),

            Stat::make('Unique Aggregates', number_format($totalAggregates))
                ->description('Active aggregate roots')
                ->descriptionIcon('heroicon-m-cube')
                ->color('success'),

            Stat::make('Snapshots', number_format($totalSnapshots))
                ->description('Stored snapshots')
                ->descriptionIcon('heroicon-m-camera')
                ->color('info'),

            Stat::make('Events Today', number_format($eventsToday))
                ->description('New events recorded today')
                ->descriptionIcon('heroicon-m-bolt')
                ->color($eventsToday > 0 ? 'success' : 'gray'),
        ];
    }
}
