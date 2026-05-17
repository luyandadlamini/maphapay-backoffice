<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\NedbankSendMoney;

use App\Filament\Admin\Pages\Wallets\AbstractTransactionsPage;
use Filament\Navigation\NavigationItem;

final class NedbankSendMoneyTransactionsPage extends AbstractTransactionsPage
{
    protected static ?string $slug = 'wallets/nedbank-send-money/transactions';
    protected static bool $shouldRegisterNavigation = false;

    public static string $providerKey = 'nedbank_send_money';
    public static string $providerLabel = 'Nedbank Send Money';
    public static string $mockEndpointPath = 'nedbank-send-money';

    public function getSubNavigation(): array
    {
        return [
            NavigationItem::make('Overview')
                ->url(static fn () => \App\Filament\Admin\Pages\Wallets\NedbankSendMoney\NedbankSendMoneyOverviewPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(\App\Filament\Admin\Pages\Wallets\NedbankSendMoney\NedbankSendMoneyOverviewPage::getRouteName())),
            NavigationItem::make('Linked accounts')
                ->url(static fn () => \App\Filament\Admin\Pages\Wallets\NedbankSendMoney\NedbankSendMoneyLinkedAccountsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(\App\Filament\Admin\Pages\Wallets\NedbankSendMoney\NedbankSendMoneyLinkedAccountsPage::getRouteName())),
            NavigationItem::make('Transactions')
                ->url(static fn () => \App\Filament\Admin\Pages\Wallets\NedbankSendMoney\NedbankSendMoneyTransactionsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(\App\Filament\Admin\Pages\Wallets\NedbankSendMoney\NedbankSendMoneyTransactionsPage::getRouteName())),
        ];
    }
}
