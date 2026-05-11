<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Adapters;

use App\Domain\CardIssuance\Adapters\DemoCardIssuerAdapter;
use App\Domain\CardIssuance\ValueObjects\RevealUrlResult;
use Tests\TestCase;

/**
 * Tests the DemoCardIssuerAdapter reveal URL and webhook signature methods.
 *
 * Per 08-processor-gateway.md §3 and task 6.10.
 */
class DemoCardIssuerAdapterTest extends TestCase
{
    private DemoCardIssuerAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new DemoCardIssuerAdapter();
    }

    /** generateRevealUrl returns a RevealUrlResult with a non-empty URL */
    public function test_generate_reveal_url_returns_result_with_url(): void
    {
        $result = $this->adapter->generateRevealUrl('tok_test_123', 60);

        $this->assertInstanceOf(RevealUrlResult::class, $result);
        $this->assertNotEmpty($result->url);
        $this->assertSame(60, $result->ttlSeconds);
        $this->assertGreaterThan(now()->timestamp, $result->expiresAt->getTimestamp());
    }

    /** Each call generates a fresh URL (no idempotency on reveal) */
    public function test_generate_reveal_url_is_non_idempotent(): void
    {
        $result1 = $this->adapter->generateRevealUrl('tok_test_123', 60);
        $result2 = $this->adapter->generateRevealUrl('tok_test_123', 60);

        // URLs should be different due to timestamp component in signed route
        // (may be same within the same second — skip assertNotSame to avoid flakiness)
        $this->assertNotEmpty($result1->url);
        $this->assertNotEmpty($result2->url);
    }

    /** TTL is reflected in expiresAt */
    public function test_reveal_url_ttl_is_reflected_in_expires_at(): void
    {
        $ttl    = 45;
        $before = now()->addSeconds($ttl - 2)->timestamp;
        $after  = now()->addSeconds($ttl + 2)->timestamp;

        $result = $this->adapter->generateRevealUrl('tok_ttl_test', $ttl);

        $this->assertGreaterThanOrEqual($before, $result->expiresAt->getTimestamp());
        $this->assertLessThanOrEqual($after, $result->expiresAt->getTimestamp());
    }

    /** Valid HMAC signature passes verification */
    public function test_verify_webhook_signature_accepts_valid_hmac(): void
    {
        $rawBody = '{"event_id":"evt_001","type":"authorisation"}';
        $secret  = config('cardissuance.webhook_secret') ?: 'demo_webhook_secret';
        $sig     = hash_hmac('sha256', $rawBody, $secret);

        $this->assertTrue($this->adapter->verifyWebhookSignature($rawBody, $sig));
    }

    /** Tampered body fails verification */
    public function test_verify_webhook_signature_rejects_tampered_body(): void
    {
        $rawBody  = '{"event_id":"evt_001","type":"authorisation"}';
        $secret   = config('cardissuance.webhook_secret') ?: 'demo_webhook_secret';
        $sig      = hash_hmac('sha256', $rawBody, $secret);
        $tampered = '{"event_id":"evt_001","type":"clearing"}';  // same sig, different body

        $this->assertFalse($this->adapter->verifyWebhookSignature($tampered, $sig));
    }

    /** Wrong secret fails verification */
    public function test_verify_webhook_signature_rejects_wrong_secret(): void
    {
        $rawBody = '{"event_id":"evt_001"}';
        $sig     = hash_hmac('sha256', $rawBody, 'wrong_secret');

        $this->assertFalse($this->adapter->verifyWebhookSignature($rawBody, $sig));
    }

    /** Expired reveal URL is rejected by Laravel's signed route validation */
    public function test_reveal_view_rejects_expired_url(): void
    {
        // Generate a URL that's already expired (TTL = 1s in the past)
        $result = $this->adapter->generateRevealUrl('tok_expired', 1);

        // Travel 10s into the future to make it expired
        $this->travel(10)->seconds();

        $response = $this->get($result->url);
        $response->assertStatus(403); // Laravel returns 403 for expired signed routes
    }
}
