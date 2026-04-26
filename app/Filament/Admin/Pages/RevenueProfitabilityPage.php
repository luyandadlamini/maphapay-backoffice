<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Analytics\DTO\CorMarginBridgePageState;
use App\Domain\Analytics\Services\WalletRevenueCorMarginBridgePresenter;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Pages\Page;

/**
 * Margin bridge when COR inputs exist (REQ-REV-003). Reserved slots + config-gated live tier.
 */
class RevenueProfitabilityPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationLabel = 'Profitability';

    protected static ?string $navigationGroup = 'Revenue & Performance';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Profitability';

    protected static string $view = 'filament.admin.pages.revenue-profitability-page';

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

    public function getCorMarginBridgeState(): CorMarginBridgePageState
    {
        return app(WalletRevenueCorMarginBridgePresenter::class)->build();
    }
}
