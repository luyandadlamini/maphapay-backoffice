<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use App\Domain\Analytics\WalletRevenueStream;
use App\Filament\Admin\Resources\CardIssuanceResource;
use App\Filament\Admin\Resources\GlobalTransactionResource;
use App\Filament\Admin\Resources\GroupSavingsResource;
use App\Filament\Admin\Resources\MerchantPartnerResource;
use App\Filament\Admin\Resources\MtnMomoTransactionResource;
use App\Filament\Admin\Resources\PaymentIntentResource;
use App\Filament\Admin\Resources\PocketResource;
use App\Filament\Admin\Resources\RewardProfileResource;

/**
 * Default Filament admin index URLs for stream “evidence” drill-downs.
 */
final class WalletRevenueStreamEvidence
{
    public static function adminUrl(WalletRevenueStream $stream): string
    {
        return match ($stream) {
            WalletRevenueStream::P2pSend,
            WalletRevenueStream::Cashout => GlobalTransactionResource::getUrl(),
            WalletRevenueStream::RequestMoney,
            WalletRevenueStream::MerchantPay    => PaymentIntentResource::getUrl(),
            WalletRevenueStream::MerchantQr     => MerchantPartnerResource::getUrl(),
            WalletRevenueStream::TopupMomo      => MtnMomoTransactionResource::getUrl(),
            WalletRevenueStream::SavingsPockets => PocketResource::getUrl(),
            WalletRevenueStream::GroupSavings   => GroupSavingsResource::getUrl(),
            WalletRevenueStream::Utilities      => PaymentIntentResource::getUrl(),
            WalletRevenueStream::Mcard          => CardIssuanceResource::getUrl(),
            WalletRevenueStream::Rewards        => RewardProfileResource::getUrl(),
        };
    }
}
