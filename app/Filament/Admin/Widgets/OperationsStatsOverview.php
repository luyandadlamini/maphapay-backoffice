<?php

namespace App\Filament\Admin\Widgets;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Compliance\Models\KycDocument;
use App\Domain\Support\Models\SupportCase;
use App\Domain\Account\Models\AdjustmentRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class OperationsStatsOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $today = Carbon::today();

        $transactionsToday = AuthorizedTransaction::whereDate('created_at', $today)->count();
        $failedToday = AuthorizedTransaction::whereDate('created_at', $today)
            ->where('status', 'failed')
            ->count();
        $failureRate = $transactionsToday > 0
            ? round(($failedToday / $transactionsToday) * 100, 1)
            : 0;

        $openCases = SupportCase::where('status', 'open')->count();
        $urgentCases = SupportCase::where('status', 'open')->where('priority', 'urgent')->count();

        $pendingKyc = KycDocument::pending()->count();

        $pendingAdjustments = AdjustmentRequest::where('status', 'pending')->count();

        return [
            Stat::make('Transactions Today', number_format($transactionsToday))
                ->description("{$failedToday} failed ({$failureRate}%)")
                ->descriptionIcon($failedToday > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($failedToday > 0 ? 'warning' : 'success')
                ->chart(
                    AuthorizedTransaction::selectRaw('COUNT(*) as count, DATE(created_at) as date')
                        ->whereBetween('created_at', [now()->subDays(6), now()])
                        ->groupBy('date')
                        ->orderBy('date')
                        ->pluck('count')
                        ->toArray()
                ),

            Stat::make('Open Support Cases', number_format($openCases))
                ->description($urgentCases > 0 ? "{$urgentCases} urgent — needs attention" : 'No urgent cases')
                ->descriptionIcon($urgentCases > 0 ? 'heroicon-m-fire' : 'heroicon-m-chat-bubble-left-ellipsis')
                ->color($urgentCases > 0 ? 'danger' : ($openCases > 0 ? 'warning' : 'success'))
                ->url(\App\Filament\Admin\Resources\SupportCaseResource::getUrl('index')),

            Stat::make('KYC Pending Review', number_format($pendingKyc))
                ->description($pendingKyc > 0 ? 'Documents awaiting verification' : 'All clear')
                ->descriptionIcon($pendingKyc > 0 ? 'heroicon-m-document-check' : 'heroicon-m-check-badge')
                ->color($pendingKyc > 10 ? 'danger' : ($pendingKyc > 0 ? 'warning' : 'success'))
                ->url(\App\Filament\Admin\Resources\KycDocumentResource::getUrl('index')),

            Stat::make('Pending Adjustments', number_format($pendingAdjustments))
                ->description($pendingAdjustments > 0 ? 'Awaiting finance-lead approval' : 'Queue empty')
                ->descriptionIcon($pendingAdjustments > 0 ? 'heroicon-m-clock' : 'heroicon-m-check-circle')
                ->color($pendingAdjustments > 0 ? 'warning' : 'success')
                ->url(\App\Filament\Admin\Resources\AdjustmentRequestResource::getUrl('index')),
        ];
    }
}
