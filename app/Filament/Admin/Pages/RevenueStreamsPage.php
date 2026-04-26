<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Analytics\DTO\WalletRevenueActivityResult;
use App\Domain\Analytics\Services\WalletRevenueActivityMetrics;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Carbon\Carbon;
use Filament\Pages\Page;

/**
 * One card per wallet revenue stream (REQ-REV-002). v1 activity from projections where mapped; else pending.
 */
class RevenueStreamsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Streams';

    protected static ?string $navigationGroup = 'Revenue & Performance';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Revenue streams';

    protected static string $view = 'filament.admin.pages.revenue-streams-page';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        $access = app(BackofficeWorkspaceAccess::class);

        return $access->canAccess('finance', $user)
            || $access->canAccess('platform_administration', $user);
    }

    public function getStreamsActivity(): WalletRevenueActivityResult
    {
        $days = max(1, (int) config('maphapay.revenue_streams_default_activity_window_days', 30));
        $end = Carbon::now()->endOfDay();
        $start = Carbon::now()->copy()->subDays($days)->startOfDay();

        return app(WalletRevenueActivityMetrics::class)->forPeriod($start, $end);
    }
}
