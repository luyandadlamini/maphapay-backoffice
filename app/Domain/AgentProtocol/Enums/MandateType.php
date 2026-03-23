<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Enums;

/**
 * AP2 Mandate Types — Verifiable Digital Credentials for agent commerce.
 *
 * Cart Mandate: Human-present shopping (W3C PaymentRequest structure)
 * Intent Mandate: Human-not-present autonomous agent actions
 * Payment Mandate: Direct payment authorization
 */
enum MandateType: string
{
    case CART_MANDATE = 'cart';
    case INTENT_MANDATE = 'intent';
    case PAYMENT_MANDATE = 'payment';

    public function label(): string
    {
        return match ($this) {
            self::CART_MANDATE    => 'Cart Mandate',
            self::INTENT_MANDATE  => 'Intent Mandate',
            self::PAYMENT_MANDATE => 'Payment Mandate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CART_MANDATE    => 'Human-present shopping mandate with W3C PaymentRequest cart contents',
            self::INTENT_MANDATE  => 'Human-not-present autonomous intent with budget constraints',
            self::PAYMENT_MANDATE => 'Direct payment authorization binding payer to payee',
        };
    }

    /**
     * Whether human presence is required for this mandate type.
     */
    public function requiresHumanPresence(): bool
    {
        return match ($this) {
            self::CART_MANDATE    => true,
            self::INTENT_MANDATE  => false,
            self::PAYMENT_MANDATE => false,
        };
    }
}
