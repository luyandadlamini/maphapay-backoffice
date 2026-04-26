<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Analytics\DTO\UnitEconomicsPageState;
use App\Domain\Analytics\Services\WalletRevenueUnitEconomicsPresenter;
use App\Support\Backoffice\BackofficeWorkspaceAccess;
use Filament\Pages\Page;

/**
 * CAC / LTV when marketing + finance feeds exist (REQ-REV-004). Reserved slots + config-gated live tier.
 */
class RevenueUnitEconomicsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Unit economics';

    protected static ?string $navigationGroup = 'Revenue & Performance';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Unit economics';

    protected static string $view = 'filament.admin.pages.revenue-unit-economics-page';

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

    public function getUnitEconomicsState(): UnitEconomicsPageState
    {
        return app(WalletRevenueUnitEconomicsPresenter::class)->build();
    }
}
