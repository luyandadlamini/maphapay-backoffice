<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\Fraud\Models\AnomalyDetection;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AnomalyTrendWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '60s';

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $openedThisWeek = AnomalyDetection::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count();
        $resolvedThisWeek = AnomalyDetection::whereBetween('resolved_at', [now()->startOfWeek(), now()->endOfWeek()])->count();
        $underReview = AnomalyDetection::where('triage_status', 'under_review')->count();

        $totalResolved = AnomalyDetection::whereNotNull('resolved_at')->count();
        $falsePositives = AnomalyDetection::where('resolution_type', 'false_positive')->count();
        $fpRate = $totalResolved > 0
            ? round(($falsePositives / $totalResolved) * 100, 1)
            : 0;

        return [
            Stat::make('Opened This Week', $openedThisWeek)
                ->description('New anomaly detections')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($openedThisWeek > 10 ? 'danger' : 'warning'),

            Stat::make('Resolved This Week', $resolvedThisWeek)
                ->description('Triaged and closed')
                ->icon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Under Review', $underReview)
                ->description('Assigned and being investigated')
                ->icon('heroicon-o-magnifying-glass')
                ->color($underReview > 5 ? 'warning' : 'info'),

            Stat::make('False Positive Rate', "{$fpRate}%")
                ->description('Of all resolved anomalies')
                ->icon('heroicon-o-chart-bar')
                ->color($fpRate > 30 ? 'danger' : 'success'),
        ];
    }
}
