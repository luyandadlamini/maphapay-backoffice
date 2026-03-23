<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Enums;

/**
 * Supported payment rails for the Machine Payments Protocol.
 *
 * Each rail represents a distinct payment method that can settle
 * MPP challenges: Stripe Payment Tokens (fiat), Tempo stablecoins,
 * Lightning Network (Bitcoin), and traditional card networks.
 */
enum PaymentRail: string
{
    case STRIPE_SPT = 'stripe';
    case TEMPO = 'tempo';
    case LIGHTNING = 'lightning';
    case CARD = 'card';

    /**
     * Human-readable label for this rail.
     */
    public function label(): string
    {
        return match ($this) {
            self::STRIPE_SPT => 'Stripe Payment Token',
            self::TEMPO      => 'Tempo Stablecoin',
            self::LIGHTNING  => 'Lightning Network',
            self::CARD       => 'Card Network',
        };
    }

    /**
     * Short description of the rail's payment mechanism.
     */
    public function description(): string
    {
        return match ($this) {
            self::STRIPE_SPT => 'Single-use Stripe Payment Tokens (spt_) settled via PaymentIntents API',
            self::TEMPO      => 'TIP-20 stablecoin transfers on Tempo blockchain (chain 42431)',
            self::LIGHTNING  => 'BOLT11 invoice payments with preimage-based proof of payment',
            self::CARD       => 'Encrypted network tokens via JWE (RSA-OAEP-256 + AES-256-GCM)',
        };
    }

    /**
     * Whether this rail supports fiat currencies.
     */
    public function supportsFiat(): bool
    {
        return match ($this) {
            self::STRIPE_SPT, self::CARD => true,
            self::TEMPO, self::LIGHTNING => false,
        };
    }

    /**
     * Whether this rail supports crypto/stablecoin currencies.
     */
    public function supportsCrypto(): bool
    {
        return match ($this) {
            self::TEMPO, self::LIGHTNING => true,
            self::STRIPE_SPT, self::CARD => false,
        };
    }

    /**
     * Default supported currencies for this rail.
     *
     * @return array<string>
     */
    public function defaultCurrencies(): array
    {
        return match ($this) {
            self::STRIPE_SPT => ['USD', 'EUR', 'GBP'],
            self::TEMPO      => ['USDC', 'USDT'],
            self::LIGHTNING  => ['BTC'],
            self::CARD       => ['USD', 'EUR', 'GBP'],
        };
    }
}
