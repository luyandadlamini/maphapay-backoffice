<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\StandardUnayo;

use App\Filament\Admin\Pages\Wallets\AbstractTransactionsPage;
use Filament\Navigation\NavigationItem;

final class StandardUnayoTransactionsPage extends AbstractTransactionsPage
{
    protected static ?string $slug = 'wallets/standard-unayo/transactions';
    protected static bool $shouldRegisterNavigation = false;

    public static string $providerKey = 'standard_unayo';
    public static string $providerLabel = 'Standard Unayo';
    public static string $mockEndpointPath = 'standard-unayo';

    public function getSubNavigation(): array
    {
        return [
            NavigationItem::make('Overview')
                ->url(static fn () => StandardUnayoOverviewPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(StandardUnayoOverviewPage::getRouteName())),
            NavigationItem::make('Linked accounts')
                ->url(static fn () => StandardUnayoLinkedAccountsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(StandardUnayoLinkedAccountsPage::getRouteName())),
            NavigationItem::make('Transactions')
                ->url(static fn () => StandardUnayoTransactionsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(StandardUnayoTransactionsPage::getRouteName())),
        ];
    }
}
