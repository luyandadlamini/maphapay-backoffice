<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CardIssuance\Adapters;

use App\Domain\CardIssuance\Adapters\StripeCardIssuerAdapter;
use App\Domain\CardIssuance\ValueObjects\StripeUsdToSzlConverter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stripe\StripeClient;

class StripeCardIssuerAdapterCardholderPayloadTest extends TestCase
{
    public function test_default_billing_address_is_physical_not_po_box(): void
    {
        $adapter = new StripeCardIssuerAdapter(
            stripe: new StripeClient(['api_key' => 'sk_test_fake']),
            converter: new StripeUsdToSzlConverter(rate: 18.50),
            webhookSecret: 'whsec_test',
        );

        $reflection = new ReflectionClass($adapter);
        $method = $reflection->getMethod('defaultBillingAddress');

        $address = $method->invoke($adapter);

        $this->assertIsArray($address);
        $this->assertSame('US', $address['country']);
        $this->assertArrayHasKey('line1', $address);
        $this->assertStringNotContainsStringIgnoringCase('po box', (string) $address['line1']);
        $this->assertStringNotContainsStringIgnoringCase('p.o.', (string) $address['line1']);
    }

    public function test_individual_details_include_required_issuing_terms_acceptance(): void
    {
        $adapter = new StripeCardIssuerAdapter(
            stripe: new StripeClient(['api_key' => 'sk_test_fake']),
            converter: new StripeUsdToSzlConverter(rate: 18.50),
            webhookSecret: 'whsec_test',
        );

        $reflection = new ReflectionClass($adapter);
        $method = $reflection->getMethod('individualDetails');

        $details = $method->invoke($adapter, 'Test User');

        $this->assertIsArray($details);
        $this->assertSame('Test', $details['first_name']);
        $this->assertSame('User', $details['last_name']);
        $this->assertArrayHasKey('card_issuing', $details);
        $this->assertSame('127.0.0.1', $details['card_issuing']['user_terms_acceptance']['ip']);
        $this->assertIsInt($details['card_issuing']['user_terms_acceptance']['date']);
    }
}
