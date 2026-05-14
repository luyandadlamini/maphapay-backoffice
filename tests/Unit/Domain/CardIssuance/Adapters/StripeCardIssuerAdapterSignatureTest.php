<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CardIssuance\Adapters;

use App\Domain\CardIssuance\Adapters\StripeCardIssuerAdapter;
use App\Domain\CardIssuance\ValueObjects\StripeUsdToSzlConverter;
use Stripe\StripeClient;
use Tests\TestCase;

class StripeCardIssuerAdapterSignatureTest extends TestCase
{
    private StripeCardIssuerAdapter $adapter;

    private string $webhookSecret = 'whsec_test_abc123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new StripeCardIssuerAdapter(
            stripe: new StripeClient(['api_key' => 'sk_test_fake']),
            converter: new StripeUsdToSzlConverter(rate: 18.50),
            webhookSecret: $this->webhookSecret,
        );
    }

    public function test_valid_signature_passes(): void
    {
        $payload = '{"id":"evt_test_123","type":"issuing_transaction.created"}';
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->webhookSecret);
        $header = "t={$timestamp},v1={$signature}";

        $this->assertTrue($this->adapter->verifyWebhookSignature($payload, $header));
    }

    public function test_tampered_payload_fails(): void
    {
        $payload = '{"id":"evt_test_123","type":"issuing_transaction.created"}';
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->webhookSecret);
        $header = "t={$timestamp},v1={$signature}";

        $tamperedPayload = '{"id":"evt_test_123","type":"issuing_card.updated"}';

        $this->assertFalse($this->adapter->verifyWebhookSignature($tamperedPayload, $header));
    }

    public function test_wrong_secret_fails(): void
    {
        $payload = '{"id":"evt_test_123"}';
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_WRONG');
        $header = "t={$timestamp},v1={$signature}";

        $this->assertFalse($this->adapter->verifyWebhookSignature($payload, $header));
    }
}
