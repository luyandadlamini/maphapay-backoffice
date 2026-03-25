<?php

declare(strict_types=1);

use App\Domain\X402\Models\WebSocketSubscription;
use App\Domain\X402\Services\WebSocketPaymentService;
use App\Http\Middleware\WebSocketPaymentGateMiddleware;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

describe('WebSocketPaymentGateMiddleware payment header processing', function (): void {
    beforeEach(function (): void {
        config(['websocket.premium_channels' => [
            'tenant.*.exchange.orderbook' => [
                'price'            => '1000',
                'protocol'         => 'x402',
                'duration_seconds' => 3600,
            ],
        ]]);
    });

    it('creates subscription when valid x402 payment header is provided', function (): void {
        $user = User::factory()->create();

        $payload = base64_encode((string) json_encode(['payment_id' => 'pay_x402_abc123']));

        $middleware = app(WebSocketPaymentGateMiddleware::class);
        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-tenant.1.exchange.orderbook',
        ]);
        $request->setUserResolver(fn () => $user);
        $request->headers->set('PAYMENT-SIGNATURE', $payload);

        $response = $middleware->handle($request, fn () => new Response('ok'));

        expect($response->getStatusCode())->toBe(200);

        $subscription = WebSocketSubscription::where('user_id', $user->id)
            ->where('channel', 'tenant.1.exchange.orderbook')
            ->first();

        assert($subscription instanceof WebSocketSubscription);
        expect($subscription->protocol)->toBe('x402');
        expect($subscription->payment_id)->toBe('pay_x402_abc123');
        expect($subscription->isActive())->toBeTrue();
    });

    it('creates subscription when valid mpp payment header is provided', function (): void {
        $user = User::factory()->create();

        $credential = base64_encode((string) json_encode(['challenge_id' => 'ch_mpp_def456']));

        $middleware = app(WebSocketPaymentGateMiddleware::class);
        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-tenant.1.exchange.orderbook',
        ]);
        $request->setUserResolver(fn () => $user);
        $request->headers->set('Authorization', 'Payment ' . $credential);

        $response = $middleware->handle($request, fn () => new Response('ok'));

        expect($response->getStatusCode())->toBe(200);

        $subscription = WebSocketSubscription::where('user_id', $user->id)
            ->where('channel', 'tenant.1.exchange.orderbook')
            ->first();

        assert($subscription instanceof WebSocketSubscription);
        expect($subscription->protocol)->toBe('mpp');
        expect($subscription->payment_id)->toBe('ch_mpp_def456');
        expect($subscription->isActive())->toBeTrue();
    });

    it('returns 402 when payment header is malformed', function (): void {
        $user = User::factory()->create();

        $middleware = app(WebSocketPaymentGateMiddleware::class);
        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-tenant.1.exchange.orderbook',
        ]);
        $request->setUserResolver(fn () => $user);
        $request->headers->set('PAYMENT-SIGNATURE', '!!!not-valid-base64!!!');

        $response = $middleware->handle($request, fn () => new Response('ok'));

        expect($response->getStatusCode())->toBe(402);

        $body = json_decode((string) $response->getContent(), true);
        assert(is_array($body));
        expect($body['error'])->toBe('PAYMENT_REQUIRED')
            ->and($body['message'])->toContain('base64 decode failed');
    });

    it('returns 402 when x402 payload is missing payment_id', function (): void {
        $user = User::factory()->create();

        $payload = base64_encode((string) json_encode(['amount' => '1000']));

        $middleware = app(WebSocketPaymentGateMiddleware::class);
        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-tenant.1.exchange.orderbook',
        ]);
        $request->setUserResolver(fn () => $user);
        $request->headers->set('PAYMENT-SIGNATURE', $payload);

        $response = $middleware->handle($request, fn () => new Response('ok'));

        expect($response->getStatusCode())->toBe(402);

        $body = json_decode((string) $response->getContent(), true);
        assert(is_array($body));
        expect($body['message'])->toContain('missing payment_id');
    });

    it('returns 402 when mpp payload is missing challenge_id', function (): void {
        $user = User::factory()->create();

        $credential = base64_encode((string) json_encode(['token' => 'something']));

        $middleware = app(WebSocketPaymentGateMiddleware::class);
        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-tenant.1.exchange.orderbook',
        ]);
        $request->setUserResolver(fn () => $user);
        $request->headers->set('Authorization', 'Payment ' . $credential);

        $response = $middleware->handle($request, fn () => new Response('ok'));

        expect($response->getStatusCode())->toBe(402);

        $body = json_decode((string) $response->getContent(), true);
        assert(is_array($body));
        expect($body['message'])->toContain('missing challenge_id');
    });

    it('creates subscription for agent via X-Agent-ID header', function (): void {
        $payload = base64_encode((string) json_encode(['payment_id' => 'pay_agent_xyz']));

        $middleware = app(WebSocketPaymentGateMiddleware::class);
        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-tenant.1.exchange.orderbook',
        ]);
        $request->headers->set('X-Agent-ID', 'agent-007');
        $request->headers->set('PAYMENT-SIGNATURE', $payload);

        $response = $middleware->handle($request, fn () => new Response('ok'));

        expect($response->getStatusCode())->toBe(200);

        $subscription = WebSocketSubscription::where('agent_id', 'agent-007')
            ->where('channel', 'tenant.1.exchange.orderbook')
            ->first();

        assert($subscription instanceof WebSocketSubscription);
        expect($subscription->protocol)->toBe('x402');
        expect($subscription->payment_id)->toBe('pay_agent_xyz');
    });
});

describe('WebSocketPaymentService::renewSubscription', function (): void {
    beforeEach(function (): void {
        config(['websocket.premium_channels' => [
            'tenant.*.exchange.orderbook' => [
                'price'            => '1000',
                'protocol'         => 'x402',
                'duration_seconds' => 3600,
            ],
        ]]);
    });

    it('renews existing subscription', function (): void {
        $user = User::factory()->create();
        $originalExpiry = now()->addMinutes(30);

        WebSocketSubscription::create([
            'user_id'    => $user->id,
            'channel'    => 'tenant.1.exchange.orderbook',
            'protocol'   => 'x402',
            'amount'     => '1000',
            'expires_at' => $originalExpiry,
        ]);

        $service = app(WebSocketPaymentService::class);
        $renewed = $service->renewSubscription('tenant.1.exchange.orderbook', $user->id, null);

        assert($renewed instanceof WebSocketSubscription);
        expect($renewed->expires_at->timestamp)->toBeGreaterThan($originalExpiry->timestamp);
    });

    it('returns null when no active subscription exists', function (): void {
        $user = User::factory()->create();

        $service = app(WebSocketPaymentService::class);
        $result = $service->renewSubscription('tenant.1.exchange.orderbook', $user->id, null);

        expect($result)->toBeNull();
    });

    it('returns null when no user or agent provided', function (): void {
        $service = app(WebSocketPaymentService::class);
        $result = $service->renewSubscription('tenant.1.exchange.orderbook', null, null);

        expect($result)->toBeNull();
    });

    it('renews subscription for agent', function (): void {
        $originalExpiry = now()->addMinutes(15);

        WebSocketSubscription::create([
            'agent_id'   => 'agent-renewal',
            'channel'    => 'tenant.1.exchange.orderbook',
            'protocol'   => 'mpp',
            'amount'     => '1000',
            'expires_at' => $originalExpiry,
        ]);

        $service = app(WebSocketPaymentService::class);
        $renewed = $service->renewSubscription('tenant.1.exchange.orderbook', null, 'agent-renewal');

        assert($renewed instanceof WebSocketSubscription);
        expect($renewed->expires_at->timestamp)->toBeGreaterThan($originalExpiry->timestamp);
    });
});
