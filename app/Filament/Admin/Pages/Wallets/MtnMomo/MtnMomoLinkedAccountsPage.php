<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\MtnMomo;

use App\Filament\Admin\Pages\Wallets\AbstractLinkedAccountsPage;
use Filament\Navigation\NavigationItem;

final class MtnMomoLinkedAccountsPage extends AbstractLinkedAccountsPage
{
    protected static ?string $slug = 'wallets/mtn-momo/linked-accounts';
    protected static bool $shouldRegisterNavigation = false;

    public static string $providerKey = 'mtn_momo';
    public static string $providerLabel = 'MTN MoMo';
    public static string $mockEndpointPath = 'mtn-momo';

    public function getSubNavigation(): array
    {
        return [
            NavigationItem::make('Overview')
                ->url(static fn () => \App\Filament\Admin\Pages\Wallets\MtnMomo\MtnMomoOverviewPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(\App\Filament\Admin\Pages\Wallets\MtnMomo\MtnMomoOverviewPage::getRouteName())),
            NavigationItem::make('Linked accounts')
                ->url(static fn () => \App\Filament\Admin\Pages\Wallets\MtnMomo\MtnMomoLinkedAccountsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(\App\Filament\Admin\Pages\Wallets\MtnMomo\MtnMomoLinkedAccountsPage::getRouteName())),
            NavigationItem::make('Transactions')
                ->url(static fn () => \App\Filament\Admin\Pages\Wallets\MtnMomo\MtnMomoTransactionsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(\App\Filament\Admin\Pages\Wallets\MtnMomo\MtnMomoTransactionsPage::getRouteName())),
        ];
    }
}
