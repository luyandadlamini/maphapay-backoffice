<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PollResource\Widgets;

use App\Domain\Governance\Enums\PollStatus;
use App\Domain\Governance\Models\Poll;
use App\Domain\Governance\Models\Vote;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Stancl\Tenancy\Tenancy;

class GovernanceStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        if (! $this->hasActiveTenantContext()) {
            return $this->emptyStats();
        }

        $totalPolls = Poll::count();
        $activePolls = Poll::where('status', PollStatus::ACTIVE)->count();
        $totalVotes = Vote::count();
        $totalVotingPower = Vote::sum('voting_power');

        $pollsThisMonth = Poll::where('created_at', '>=', now()->subMonth())->count();
        $pollsLastMonth = Poll::where('created_at', '>=', now()->subMonths(2))
            ->where('created_at', '<', now()->subMonth())
            ->count();

        $pollGrowth = $pollsLastMonth > 0
            ? (($pollsThisMonth - $pollsLastMonth) / $pollsLastMonth) * 100
            : ($pollsThisMonth > 0 ? 100 : 0);

        $votesThisWeek = Vote::where('voted_at', '>=', now()->subWeek())->count();
        $votesLastWeek = Vote::where('voted_at', '>=', now()->subWeeks(2))
            ->where('voted_at', '<', now()->subWeek())
            ->count();

        $voteGrowth = $votesLastWeek > 0
            ? (($votesThisWeek - $votesLastWeek) / $votesLastWeek) * 100
            : ($votesThisWeek > 0 ? 100 : 0);

        $participationRate = $totalPolls > 0
            ? ($totalVotes / max($totalPolls, 1))
            : 0;

        return [
            Stat::make('Total Polls', $totalPolls)
                ->description($pollGrowth >= 0 ? "+{$pollGrowth}% from last month" : "{$pollGrowth}% from last month")
                ->descriptionIcon($pollGrowth >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($pollGrowth >= 0 ? 'success' : 'danger')
                ->chart($this->getPollsChart()),

            Stat::make('Active Polls', $activePolls)
                ->description('Currently accepting votes')
                ->descriptionIcon('heroicon-m-play')
                ->color('warning'),

            Stat::make('Total Votes', number_format($totalVotes))
                ->description($voteGrowth >= 0 ? "+{$voteGrowth}% from last week" : "{$voteGrowth}% from last week")
                ->descriptionIcon($voteGrowth >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($voteGrowth >= 0 ? 'success' : 'danger')
                ->chart($this->getVotesChart()),

            Stat::make('Total Voting Power', number_format($totalVotingPower))
                ->description('Cumulative voting power')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('info'),

            Stat::make('Avg. Participation', number_format($participationRate, 1) . ' votes/poll')
                ->description('Average votes per poll')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('Recent Activity', $votesThisWeek . ' votes this week')
                ->description('Vote activity trend')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('gray'),
        ];
    }

    private function getPollsChart(): array
    {
        if (! $this->hasActiveTenantContext()) {
            return array_fill(0, 7, 0);
        }

        // Get polls created over last 7 days
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $count = Poll::whereDate('created_at', $date)->count();
            $data[] = $count;
        }

        return $data;
    }

    private function getVotesChart(): array
    {
        if (! $this->hasActiveTenantContext()) {
            return array_fill(0, 7, 0);
        }

        // Get votes cast over last 7 days
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $count = Vote::whereDate('voted_at', $date)->count();
            $data[] = $count;
        }

        return $data;
    }

    private function emptyStats(): array
    {
        return [
            Stat::make('Total Polls', 0)
                ->description('+0% from last month')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->chart(array_fill(0, 7, 0)),

            Stat::make('Active Polls', 0)
                ->description('Currently accepting votes')
                ->descriptionIcon('heroicon-m-play')
                ->color('warning'),

            Stat::make('Total Votes', '0')
                ->description('+0% from last week')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->chart(array_fill(0, 7, 0)),

            Stat::make('Total Voting Power', '0')
                ->description('Cumulative voting power')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('info'),

            Stat::make('Avg. Participation', '0.0 votes/poll')
                ->description('Average votes per poll')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('Recent Activity', '0 votes this week')
                ->description('Vote activity trend')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('gray'),
        ];
    }

    private function hasActiveTenantContext(): bool
    {
        /** @var Tenancy $tenancy */
        $tenancy = app(Tenancy::class);

        return $tenancy->initialized;
    }
}
