<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\Emali;

use App\Filament\Admin\Pages\Wallets\AbstractWalletProviderPage;

final class EmaliWalletPage extends AbstractWalletProviderPage
{
    protected static ?string $slug = 'wallets/emali';

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?int $navigationSort = 2;

    public static string $providerKey = 'emali_eswatini_mobile';

    public static string $providerLabel = 'eMali';

    public static string $mockEndpointPath = 'emali';
}
