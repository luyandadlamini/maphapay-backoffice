<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Enums;

enum PricingCategory: string
{
    case LocalTransfer = 'local_transfer';
    case InternationalTransfer = 'international_transfer';
    case WalletToWallet = 'wallet_to_wallet';
    case BankTransfer = 'bank_transfer';
    case AtmWithdrawal = 'atm_withdrawal';
    case PosTransaction = 'pos_transaction';
    case VirtualCardTransaction = 'virtual_card_transaction';
    case PhysicalCardTransaction = 'physical_card_transaction';
    case MerchantPayment = 'merchant_payment';
    case Airtime = 'airtime';
    case BillPayment = 'bill_payment';
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case FxConversion = 'fx_conversion';
    case CrossBorder = 'cross_border';

    public function label(): string
    {
        return match ($this) {
            self::LocalTransfer            => __('Local transfer'),
            self::InternationalTransfer    => __('International transfer'),
            self::WalletToWallet           => __('Wallet-to-wallet'),
            self::BankTransfer             => __('Bank transfer'),
            self::AtmWithdrawal            => __('ATM withdrawal'),
            self::PosTransaction           => __('POS transaction'),
            self::VirtualCardTransaction   => __('Virtual card transaction'),
            self::PhysicalCardTransaction  => __('Physical card transaction'),
            self::MerchantPayment          => __('Merchant payment'),
            self::Airtime                  => __('Airtime'),
            self::BillPayment              => __('Bill payment'),
            self::CashIn                   => __('Cash in'),
            self::CashOut                  => __('Cash out'),
            self::FxConversion             => __('FX conversion'),
            self::CrossBorder              => __('Cross-border'),
        };
    }
}
