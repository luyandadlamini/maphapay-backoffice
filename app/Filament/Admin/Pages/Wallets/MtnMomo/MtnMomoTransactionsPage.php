<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\MtnMomo;

use App\Filament\Admin\Pages\Wallets\AbstractTransactionsPage;
use Filament\Navigation\NavigationItem;

final class MtnMomoTransactionsPage extends AbstractTransactionsPage
{
    protected static ?string $slug = 'wallets/mtn-momo/transactions';

    protected static bool $shouldRegisterNavigation = false;

    public static string $providerKey = 'mtn_momo';

    public static string $providerLabel = 'MTN MoMo';

    public static string $mockEndpointPath = 'mtn-momo';

    public function getSubNavigation(): array
    {
        return [
            NavigationItem::make('Overview')
                ->url(static fn () => MtnMomoOverviewPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(MtnMomoOverviewPage::getRouteName())),
            NavigationItem::make('Linked accounts')
                ->url(static fn () => MtnMomoLinkedAccountsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(MtnMomoLinkedAccountsPage::getRouteName())),
            NavigationItem::make('Transactions')
                ->url(static fn () => MtnMomoTransactionsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(MtnMomoTransactionsPage::getRouteName())),
        ];
    }
}
