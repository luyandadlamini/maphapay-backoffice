<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/**
 * Wallet revenue / volume taxonomy (spec §6). Stable string values for APIs and reporting.
 */
enum WalletRevenueStream: string
{
    case P2pSend = 'p2p_send';
    case RequestMoney = 'request_money';
    case MerchantQr = 'merchant_qr';
    case MerchantPay = 'merchant_pay';
    case TopupMomo = 'topup_momo';
    case Cashout = 'cashout';
    case SavingsPockets = 'savings_pockets';
    case GroupSavings = 'group_savings';
    case Utilities = 'utilities';
    case Mcard = 'mcard';
    case Rewards = 'rewards';

    public function label(): string
    {
        return match ($this) {
            self::P2pSend        => __('Send money'),
            self::RequestMoney   => __('Request money / pay links'),
            self::MerchantQr     => __('QR pay'),
            self::MerchantPay    => __('Pay merchant'),
            self::TopupMomo      => __('Top-up (MoMo) / linked wallet funding'),
            self::Cashout        => __('Cash-out / payout'),
            self::SavingsPockets => __('Pockets / goals'),
            self::GroupSavings   => __('Group savings / stokvel'),
            self::Utilities      => __('Utilities / airtime'),
            self::Mcard          => __('Virtual / physical cards'),
            self::Rewards        => __('Rewards (cost / liability analytics)'),
        };
    }

    public function defaultCurrency(): string
    {
        return match ($this) {
            self::TopupMomo,
            self::Cashout,
            self::P2pSend,
            self::RequestMoney,
            self::SavingsPockets,
            self::GroupSavings => 'SZL',
            self::MerchantQr,
            self::MerchantPay => 'ZAR',
            self::Utilities   => 'ZAR',
            self::Mcard       => 'ZAR',
            self::Rewards     => 'SZL',
        };
    }

    /**
     * Short copy for admin cards until finance publishes mapping docs.
     */
    public function description(): string
    {
        return match ($this) {
            self::P2pSend        => __('Peer-to-peer wallet transfers and related ledger activity.'),
            self::RequestMoney   => __('Payment intents, pay links, and request-money flows.'),
            self::MerchantQr     => __('Merchant QR acceptance and partner configuration.'),
            self::MerchantPay    => __('In-person or online merchant checkout tied to payment intents.'),
            self::TopupMomo      => __('MoMo and linked-wallet funding events.'),
            self::Cashout        => __('User cash-out, payout, and withdrawal-related activity.'),
            self::SavingsPockets => __('Goal pockets and personal savings balances.'),
            self::GroupSavings   => __('Group pockets (stokvel) and pooled savings.'),
            self::Utilities      => __('Bill pay, airtime, and third-party utility flows where applicable.'),
            self::Mcard          => __('Card issuance, limits, and card-present or card-not-present spend.'),
            self::Rewards        => __('Quests, profiles, and shop — treat as cost / liability unless finance defines fee income.'),
        };
    }
}
