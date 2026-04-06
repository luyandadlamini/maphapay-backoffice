<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\Fraud\Models\AnomalyDetection;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FraudResolutionRateWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $totalDetected = AnomalyDetection::where('triage_status', AnomalyDetection::TRIAGE_STATUS_DETECTED)->count();
        $totalUnderReview = AnomalyDetection::where('triage_status', AnomalyDetection::TRIAGE_STATUS_UNDER_REVIEW)->count();
        $totalResolved = AnomalyDetection::whereIn('triage_status', [
            AnomalyDetection::TRIAGE_STATUS_RESOLVED,
            AnomalyDetection::TRIAGE_STATUS_DISMISSED,
        ])->count();

        $totalTriageCount = AnomalyDetection::count();
        $resolutionRate = $totalTriageCount > 0
            ? round(($totalResolved / $totalTriageCount) * 100, 1)
            : 0;

        return [
            Stat::make('Detected Anomalies', $totalDetected)
                ->description('New cases needing triage')
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color('danger'),
            Stat::make('Triage Resolution Rate', $resolutionRate . '%')
                ->description('Resolved vs Total Cases')
                ->descriptionIcon('heroicon-m-check-badge')
                ->chart([7, 2, 10, 3, 15, 4, 17])
                ->color('success'),
            Stat::make('Under Review', $totalUnderReview)
                ->description('Currently being investigated')
                ->descriptionIcon('heroicon-m-magnifying-glass')
                ->color('warning'),
        ];
    }
}
