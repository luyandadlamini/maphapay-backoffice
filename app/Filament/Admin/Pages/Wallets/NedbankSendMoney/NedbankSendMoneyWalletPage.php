<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets\NedbankSendMoney;

use App\Filament\Admin\Pages\Wallets\AbstractWalletProviderPage;

final class NedbankSendMoneyWalletPage extends AbstractWalletProviderPage
{
    protected static ?string $slug = 'wallets/nedbank-send-money';

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?int $navigationSort = 5;

    public static string $providerKey = 'nedbank_send_money';

    public static string $providerLabel = 'Nedbank Send Money';

    public static string $mockEndpointPath = 'nedbank-send-money';
}
