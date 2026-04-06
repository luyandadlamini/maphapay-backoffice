<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ExchangeRateResource\Widgets;

use App\Domain\Asset\Models\ExchangeRate;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ExchangeRateFreshnessWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $rates = ExchangeRate::valid()->get();

        $freshCount = $rates->filter(fn ($r) => $r->getAgeInMinutes() < 60)->count();
        $staleCount = $rates->filter(fn ($r) => $r->getAgeInMinutes() >= 60 && $r->getAgeInMinutes() < 1440)->count();
        $expiredCount = $rates->filter(fn ($r) => $r->getAgeInMinutes() >= 1440)->count();
        $oldestMinutes = $rates->isNotEmpty() ? $rates->max(fn ($r) => $r->getAgeInMinutes()) : 0;

        return [
            Stat::make('Fresh (< 1h)', $freshCount)
                ->description('Rates updated within the last hour')
                ->color('success'),
            Stat::make('Stale (1h - 24h)', $staleCount)
                ->description('Rates older than 1 hour but less than 24h')
                ->color('warning'),
            Stat::make('Expired (> 24h)', $expiredCount)
                ->description('Rates not updated in over 24 hours')
                ->color('danger'),
            Stat::make('Oldest Rate Age', $this->formatAge($oldestMinutes))
                ->description('Age of the oldest valid rate')
                ->color($oldestMinutes > 1440 ? 'danger' : ($oldestMinutes > 60 ? 'warning' : 'success')),
        ];
    }

    private function formatAge(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}m";
        }

        if ($minutes < 1440) {
            $hours = intval($minutes / 60);

            return "{$hours}h";
        }

        $days = intval($minutes / 1440);

        return "{$days}d";
    }
}
