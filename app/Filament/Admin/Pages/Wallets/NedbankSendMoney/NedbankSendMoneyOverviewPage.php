<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\NedbankSendMoney;

use App\Filament\Admin\Pages\Wallets\AbstractOverviewPage;
use Filament\Navigation\NavigationItem;

final class NedbankSendMoneyOverviewPage extends AbstractOverviewPage
{
    protected static ?string $slug = 'wallets/nedbank-send-money/overview';

    protected static bool $shouldRegisterNavigation = false;

    public static string $providerKey = 'nedbank_send_money';

    public static string $providerLabel = 'Nedbank Send Money';

    public static string $mockEndpointPath = 'nedbank-send-money';

    public function getSubNavigation(): array
    {
        return [
            NavigationItem::make('Overview')
                ->url(static fn () => NedbankSendMoneyOverviewPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(NedbankSendMoneyOverviewPage::getRouteName())),
            NavigationItem::make('Linked accounts')
                ->url(static fn () => NedbankSendMoneyLinkedAccountsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(NedbankSendMoneyLinkedAccountsPage::getRouteName())),
            NavigationItem::make('Transactions')
                ->url(static fn () => NedbankSendMoneyTransactionsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(NedbankSendMoneyTransactionsPage::getRouteName())),
        ];
    }
}
