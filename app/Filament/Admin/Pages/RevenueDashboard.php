<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\Revenue\RevenueByCategoryWidget;
use App\Filament\Admin\Widgets\Revenue\RevenueBySegmentWidget;
use App\Filament\Admin\Widgets\Revenue\ScenarioComparisonWidget;
use App\Filament\Admin\Widgets\Revenue\TargetVsActualWidget;
use Filament\Pages\Page;

class RevenueDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.admin.pages.revenue-dashboard';

    protected static ?string $navigationGroup = 'Pricing & Revenue';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Revenue Dashboard';

    protected static ?string $slug = 'revenue-dashboard';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['finance', 'platform_admin']) ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            RevenueByCategoryWidget::class,
            RevenueBySegmentWidget::class,
            TargetVsActualWidget::class,
            ScenarioComparisonWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return 2;
    }
}
