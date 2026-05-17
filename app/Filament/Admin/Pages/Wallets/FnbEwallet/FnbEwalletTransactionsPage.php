<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\FnbEwallet;

use App\Filament\Admin\Pages\Wallets\AbstractTransactionsPage;
use Filament\Navigation\NavigationItem;

final class FnbEwalletTransactionsPage extends AbstractTransactionsPage
{
    protected static ?string $slug = 'wallets/fnb-ewallet/transactions';
    protected static bool $shouldRegisterNavigation = false;

    public static string $providerKey = 'fnb_ewallet';
    public static string $providerLabel = 'FNB eWallet';
    public static string $mockEndpointPath = 'fnb-ewallet';

    public function getSubNavigation(): array
    {
        return [
            NavigationItem::make('Overview')
                ->url(static fn () => \App\Filament\Admin\Pages\Wallets\FnbEwallet\FnbEwalletOverviewPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(\App\Filament\Admin\Pages\Wallets\FnbEwallet\FnbEwalletOverviewPage::getRouteName())),
            NavigationItem::make('Linked accounts')
                ->url(static fn () => \App\Filament\Admin\Pages\Wallets\FnbEwallet\FnbEwalletLinkedAccountsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(\App\Filament\Admin\Pages\Wallets\FnbEwallet\FnbEwalletLinkedAccountsPage::getRouteName())),
            NavigationItem::make('Transactions')
                ->url(static fn () => \App\Filament\Admin\Pages\Wallets\FnbEwallet\FnbEwalletTransactionsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(\App\Filament\Admin\Pages\Wallets\FnbEwallet\FnbEwalletTransactionsPage::getRouteName())),
        ];
    }
}
