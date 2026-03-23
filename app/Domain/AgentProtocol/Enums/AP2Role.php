<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Enums;

/**
 * AP2 protocol roles per the Google Agent Payments Protocol specification.
 */
enum AP2Role: string
{
    case SHOPPING_AGENT = 'shopper';
    case CREDENTIALS_PROVIDER = 'credentials-provider';
    case MERCHANT_ENDPOINT = 'merchant';
    case PAYMENT_PROCESSOR = 'payment-processor';

    public function label(): string
    {
        return match ($this) {
            self::SHOPPING_AGENT       => 'Shopping Agent',
            self::CREDENTIALS_PROVIDER => 'Credentials Provider',
            self::MERCHANT_ENDPOINT    => 'Merchant Endpoint',
            self::PAYMENT_PROCESSOR    => 'Payment Processor',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SHOPPING_AGENT       => 'AI agent that discovers products, builds carts, and obtains user authorization',
            self::CREDENTIALS_PROVIDER => 'Secure entity managing payment credentials (digital wallet)',
            self::MERCHANT_ENDPOINT    => 'Seller-side AI agent or web interface',
            self::PAYMENT_PROCESSOR    => 'Processes payment authorization via card networks or blockchain',
        };
    }
}
