<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\FnbEwallet;

use App\Filament\Admin\Pages\Wallets\AbstractWalletProviderPage;

final class FnbEwalletWalletPage extends AbstractWalletProviderPage
{
    protected static ?string $slug = 'wallets/fnb-ewallet';

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?int $navigationSort = 3;

    public static string $providerKey = 'fnb_ewallet';

    public static string $providerLabel = 'FNB eWallet';

    public static string $mockEndpointPath = 'fnb-ewallet';
}
