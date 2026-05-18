<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\FnbEwallet;

use App\Filament\Admin\Pages\Wallets\AbstractOverviewPage;
use Filament\Navigation\NavigationItem;

final class FnbEwalletOverviewPage extends AbstractOverviewPage
{
    protected static ?string $slug = 'wallets/fnb-ewallet/overview';

    protected static bool $shouldRegisterNavigation = false;

    public static string $providerKey = 'fnb_ewallet';

    public static string $providerLabel = 'FNB eWallet';

    public static string $mockEndpointPath = 'fnb-ewallet';

    public function getSubNavigation(): array
    {
        return [
            NavigationItem::make('Overview')
                ->url(static fn () => FnbEwalletOverviewPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(FnbEwalletOverviewPage::getRouteName())),
            NavigationItem::make('Linked accounts')
                ->url(static fn () => FnbEwalletLinkedAccountsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(FnbEwalletLinkedAccountsPage::getRouteName())),
            NavigationItem::make('Transactions')
                ->url(static fn () => FnbEwalletTransactionsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(FnbEwalletTransactionsPage::getRouteName())),
        ];
    }
}
