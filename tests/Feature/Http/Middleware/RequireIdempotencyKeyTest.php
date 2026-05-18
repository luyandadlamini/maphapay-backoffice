<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\RequireIdempotencyKey;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit-level coverage for the RequireIdempotencyKey middleware. Behaviour:
 *   - GET/HEAD/OPTIONS requests pass through unchanged (no key required).
 *   - POST/PUT/PATCH requests without an Idempotency-Key header return 400
 *     with the compat error envelope.
 *   - Either `Idempotency-Key` or `X-Idempotency-Key` satisfies the guard.
 */
class RequireIdempotencyKeyTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function it_passes_through_get_requests_without_a_key(): void
    {
        $request = Request::create('/api/v2/transfers/abc/status', 'GET');

        $response = (new RequireIdempotencyKey())->handle(
            $request,
            fn () => response()->json(['ok' => true], 200),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_400_for_post_without_idempotency_key(): void
    {
        $request = Request::create('/api/v2/transfers', 'POST', ['amount' => '1.00']);

        $response = (new RequireIdempotencyKey())->handle(
            $request,
            fn () => response()->json(['ok' => true], 201),
        );

        $this->assertSame(400, $response->getStatusCode());

        /** @var array{status: string, message: array<int,string>, data: mixed} $body */
        $body = json_decode($response->getContent(), true);
        $this->assertSame('error', $body['status']);
        $this->assertSame(['Idempotency-Key header is required for this endpoint.'], $body['message']);
        $this->assertNull($body['data']);
    }

    #[Test]
    public function it_passes_through_post_with_standard_idempotency_key_header(): void
    {
        $request = Request::create('/api/v2/transfers', 'POST', ['amount' => '1.00']);
        $request->headers->set('Idempotency-Key', '550e8400-e29b-41d4-a716-446655440000');

        $response = (new RequireIdempotencyKey())->handle(
            $request,
            fn () => response()->json(['ok' => true], 201),
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function it_passes_through_post_with_x_idempotency_key_header(): void
    {
        $request = Request::create('/api/v2/transfers', 'POST', ['amount' => '1.00']);
        $request->headers->set('X-Idempotency-Key', 'k_abc1234567890123');

        $response = (new RequireIdempotencyKey())->handle(
            $request,
            fn () => response()->json(['ok' => true], 201),
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_400_when_idempotency_key_header_is_whitespace_only(): void
    {
        $request = Request::create('/api/v2/transfers', 'POST', ['amount' => '1.00']);
        $request->headers->set('Idempotency-Key', '   ');

        $response = (new RequireIdempotencyKey())->handle(
            $request,
            fn () => response()->json(['ok' => true], 201),
        );

        $this->assertSame(400, $response->getStatusCode());
    }
}
