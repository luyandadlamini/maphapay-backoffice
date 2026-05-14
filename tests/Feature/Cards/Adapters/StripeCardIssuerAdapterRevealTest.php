<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Adapters;

use App\Domain\CardIssuance\Adapters\StripeCardIssuerAdapter;
use App\Domain\CardIssuance\ValueObjects\RevealUrlResult;
use App\Domain\CardIssuance\ValueObjects\StripeUsdToSzlConverter;
use Stripe\StripeClient;
use Tests\TestCase;

class StripeCardIssuerAdapterRevealTest extends TestCase
{
    public function test_generate_reveal_url_returns_signed_hosted_page_url(): void
    {
        $adapter = new StripeCardIssuerAdapter(
            stripe: new StripeClient(['api_key' => 'sk_test_fake']),
            converter: new StripeUsdToSzlConverter(rate: 18.50),
            webhookSecret: 'whsec_test',
        );

        $result = $adapter->generateRevealUrl('ic_test_123', 60);

        $this->assertInstanceOf(RevealUrlResult::class, $result);
        $this->assertStringContainsString('/stripe-cards/reveal', $result->url);
        $this->assertStringContainsString('signature=', $result->url);
        $this->assertSame(60, $result->ttlSeconds);
        $this->assertNull($result->ephemeralKey);
        $this->assertSame('ic_test_123', $result->stripeCardId);
    }
}
