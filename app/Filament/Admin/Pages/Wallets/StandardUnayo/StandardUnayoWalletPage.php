<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\StandardUnayo;

use App\Filament\Admin\Pages\Wallets\AbstractWalletProviderPage;

final class StandardUnayoWalletPage extends AbstractWalletProviderPage
{
    protected static ?string $slug = 'wallets/standard-unayo';
    protected static ?string $navigationIcon = 'heroicon-o-building-library';
    protected static ?int $navigationSort = 4;

    public static string $providerKey = 'standard_unayo';
    public static string $providerLabel = 'Standard Unayo';
    public static string $mockEndpointPath = 'standard-unayo';
}
