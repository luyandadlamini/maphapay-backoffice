<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\MtnMomo;

use App\Filament\Admin\Pages\Wallets\AbstractWalletProviderPage;

final class MtnMomoWalletPage extends AbstractWalletProviderPage
{
    protected static ?string $slug = 'wallets/mtn-momo';
    protected static ?string $navigationIcon = 'heroicon-o-signal';
    protected static ?int $navigationSort = 1;

    public static string $providerKey = 'mtn_momo';
    public static string $providerLabel = 'MTN MoMo';
    public static string $mockEndpointPath = 'mtn-momo';
}
