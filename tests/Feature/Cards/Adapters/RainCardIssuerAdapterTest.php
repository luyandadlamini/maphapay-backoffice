<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Adapters;

use App\Domain\CardIssuance\Adapters\RainCardIssuerAdapter;
use App\Domain\CardIssuance\ValueObjects\RevealUrlResult;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests the RainCardIssuerAdapter using mocked HTTP fixtures.
 *
 * Per 08-processor-gateway.md §4 and task 6.11.
 */
class RainCardIssuerAdapterTest extends TestCase
{
    private function makeAdapter(): RainCardIssuerAdapter
    {
        return new RainCardIssuerAdapter([
            'base_url'   => 'https://api.rain.test',
            'api_key'    => 'test_api_key',
            'program_id' => 'prog_test_001',
        ]);
    }

    /** generateRevealUrl calls the Rain reveal endpoint and returns RevealUrlResult */
    public function test_generate_reveal_url_calls_rain_endpoint(): void
    {
        Http::fake([
            'https://api.rain.test/cards/tok_rain_123/reveal' => Http::response([
                'data' => [
                    'url'         => 'https://reveal.rainbank.io/reveal?token=abc123',
                    'expires_at'  => now()->addSeconds(60)->toIso8601String(),
                    'ttl_seconds' => 60,
                ],
            ], 200),
        ]);

        $adapter = $this->makeAdapter();
        $result  = $adapter->generateRevealUrl('tok_rain_123', 60);

        $this->assertInstanceOf(RevealUrlResult::class, $result);
        $this->assertStringContainsString('reveal.rainbank.io', $result->url);
        $this->assertSame(60, $result->ttlSeconds);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'tok_rain_123/reveal')
                && $request->data()['ttl_seconds'] === 60;
        });
    }

    /** verifyWebhookSignature uses hash_equals to prevent timing attacks */
    public function test_verify_webhook_signature_uses_constant_time_comparison(): void
    {
        $secret  = 'rain_test_secret';
        config(['cardissuance.webhook_secret' => $secret]);

        $adapter = $this->makeAdapter();
        $body    = '{"event_id":"evt_rain_001","type":"authorisation"}';
        $validSig = hash_hmac('sha256', $body, $secret);

        $this->assertTrue($adapter->verifyWebhookSignature($body, $validSig));
        $this->assertFalse($adapter->verifyWebhookSignature($body, 'bad_signature'));
        $this->assertFalse($adapter->verifyWebhookSignature('tampered_body', $validSig));
    }

    /** RainAdapter constructor requires all three config keys */
    public function test_constructor_throws_when_config_incomplete(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rain issuer requires base_url, api_key and program_id');

        new RainCardIssuerAdapter([
            'base_url' => 'https://api.rain.test',
            // missing api_key and program_id
        ]);
    }

    /** generateRevealUrl propagates HTTP errors as exceptions */
    public function test_generate_reveal_url_throws_on_http_error(): void
    {
        Http::fake([
            'https://api.rain.test/*' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        $adapter = $this->makeAdapter();
        $adapter->generateRevealUrl('tok_bad', 60);
    }
}
