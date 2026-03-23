<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Enums;

/**
 * Verifiable Digital Credential types for AP2 mandates.
 */
enum VdcType: string
{
    case CART_VDC = 'cart_vdc';
    case INTENT_VDC = 'intent_vdc';
    case PAYMENT_VDC = 'payment_vdc';

    public function label(): string
    {
        return match ($this) {
            self::CART_VDC    => 'Cart VDC',
            self::INTENT_VDC  => 'Intent VDC',
            self::PAYMENT_VDC => 'Payment VDC',
        };
    }

    /**
     * Get the corresponding mandate type.
     */
    public function mandateType(): MandateType
    {
        return match ($this) {
            self::CART_VDC    => MandateType::CART_MANDATE,
            self::INTENT_VDC  => MandateType::INTENT_MANDATE,
            self::PAYMENT_VDC => MandateType::PAYMENT_MANDATE,
        };
    }
}
