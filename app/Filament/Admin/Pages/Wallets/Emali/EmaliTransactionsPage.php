<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\Emali;

use App\Filament\Admin\Pages\Wallets\AbstractTransactionsPage;
use Filament\Navigation\NavigationItem;

final class EmaliTransactionsPage extends AbstractTransactionsPage
{
    protected static ?string $slug = 'wallets/emali/transactions';
    protected static bool $shouldRegisterNavigation = false;

    public static string $providerKey = 'emali_eswatini_mobile';
    public static string $providerLabel = 'eMali';
    public static string $mockEndpointPath = 'emali';

    public function getSubNavigation(): array
    {
        return [
            NavigationItem::make('Overview')
                ->url(static fn () => EmaliOverviewPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(EmaliOverviewPage::getRouteName())),
            NavigationItem::make('Linked accounts')
                ->url(static fn () => EmaliLinkedAccountsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(EmaliLinkedAccountsPage::getRouteName())),
            NavigationItem::make('Transactions')
                ->url(static fn () => EmaliTransactionsPage::getUrl())
                ->isActiveWhen(fn () => request()->routeIs(EmaliTransactionsPage::getRouteName())),
        ];
    }
}
