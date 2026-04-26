<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use App\Filament\Admin\Resources\AccountResource\Widgets\AccountBalanceChart;
use App\Filament\Admin\Resources\AccountResource\Widgets\AccountStatsOverview;
use App\Filament\Admin\Resources\AccountResource\Widgets\RecentTransactionsChart;
use App\Filament\Admin\Resources\AccountResource\Widgets\SystemHealthWidget;
use App\Filament\Admin\Widgets\BankAllocationWidget;
use App\Filament\Admin\Widgets\FailedMomoTransactionsWidget;
use App\Filament\Admin\Widgets\MultiBankDistributionWidget;
use App\Filament\Admin\Widgets\OperationsStatsOverview;
use App\Filament\Admin\Widgets\PrimaryBasketWidget;
use App\Models\User;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Widgets\Widget;

/**
 * Composes the admin home dashboard widget list from {@see BackofficeWorkspaceAccess} only (no parallel RBAC).
 */
final class AdminDashboardWidgets
{
    public function __construct(
        private readonly BackofficeWorkspaceAccess $workspaceAccess,
    ) {
    }

    /**
     * @return list<class-string<Widget>>
     */
    public function widgetsFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $workspaces = $this->workspaceAccess->activeWorkspaces($user);

        if ($workspaces === []) {
            return [];
        }

        $seesFinanceSurface = in_array('finance', $workspaces, true)
            || in_array('platform_administration', $workspaces, true);

        if ($seesFinanceSurface) {
            return self::financeSurfaceWidgets();
        }

        return self::operationsSurfaceWidgets();
    }

    /**
     * @return list<class-string<Widget>>
     */
    private static function financeSurfaceWidgets(): array
    {
        return [
            PrimaryBasketWidget::class,
            MultiBankDistributionWidget::class,
            BankAllocationWidget::class,
            AccountStatsOverview::class,
            RecentTransactionsChart::class,
            AccountBalanceChart::class,
            SystemHealthWidget::class,
        ];
    }

    /**
     * @return list<class-string<Widget>>
     */
    private static function operationsSurfaceWidgets(): array
    {
        return [
            OperationsStatsOverview::class,
            FailedMomoTransactionsWidget::class,
        ];
    }
}
