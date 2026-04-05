<?php

namespace App\Filament\Admin\Pages;

use App\Domain\Account\Models\AdjustmentRequest;
use App\Models\MtnMomoTransaction;
use Filament\Pages\Page;

class ExceptionsDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Exception Queue';

    protected static ?string $navigationGroup = 'Finance & Reconciliation';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.admin.pages.exceptions-dashboard';

    public static function getNavigationBadge(): ?string
    {
        $failedMomo = MtnMomoTransaction::where('status', MtnMomoTransaction::STATUS_FAILED)->count();
        $pendingAdj = AdjustmentRequest::where('status', 'pending')->count();
        $total = $failedMomo + $pendingAdj;

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Admin\Widgets\FailedMomoTransactionsWidget::class,
            \App\Filament\Admin\Widgets\PendingAdjustmentsWidget::class,
        ];
    }
}
