<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FilingScheduleResource\Widgets;

use App\Domain\RegTech\Models\FilingSchedule;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FilingDeadlineWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '300s';

    protected function getStats(): array
    {
        $upcomingSchedules = FilingSchedule::active()
            ->dueWithinDays(30)
            ->orderBy('next_due_date')
            ->get();

        $overdue = $upcomingSchedules->filter(fn ($s) => $s->isOverdue())->count();
        $dueSoon = $upcomingSchedules->filter(fn ($s) => ! $s->isOverdue() && $s->daysUntilDue() <= 7)->count();
        $dueLater = $upcomingSchedules->filter(fn ($s) => $s->daysUntilDue() > 7)->count();

        return [
            Stat::make('Overdue', $overdue)
                ->description('Filings past deadline')
                ->color($overdue > 0 ? 'danger' : 'success'),
            Stat::make('Due This Week', $dueSoon)
                ->description('Filings due within 7 days')
                ->color($dueSoon > 0 ? 'warning' : 'success'),
            Stat::make('Due This Month', $dueLater)
                ->description('Filings due within 30 days')
                ->color('info'),
            Stat::make('Total Active', FilingSchedule::active()->count())
                ->description('All active filing schedules')
                ->color('primary'),
        ];
    }
}
