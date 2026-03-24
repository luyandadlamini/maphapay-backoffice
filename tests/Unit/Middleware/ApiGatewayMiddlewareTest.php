<?php

declare(strict_types=1);

use App\Http\Middleware\ApiGatewayMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

uses(Tests\TestCase::class);

describe('ApiGatewayMiddleware', function () {
    beforeEach(function () {
        $this->middleware = new ApiGatewayMiddleware();
    });

    it('adds X-Request-Id header to the response', function () {
        $request = Request::create('/api/test', 'GET');

        $response = $this->middleware->handle($request, function () {
            return new Response('OK', 200);
        });

        expect($response->headers->has('X-Request-Id'))->toBeTrue();
        expect($response->headers->get('X-Request-Id'))->toStartWith('req_');
    });

    it('preserves an existing X-Request-Id from the incoming request', function () {
        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Request-Id', 'req_existing_12345');

        $response = $this->middleware->handle($request, function () {
            return new Response('OK', 200);
        });

        expect($response->headers->get('X-Request-Id'))->toBe('req_existing_12345');
    });

    it('adds X-API-Version header to the response', function () {
        $request = Request::create('/api/test', 'GET');

        $response = $this->middleware->handle($request, function () {
            return new Response('OK', 200);
        });

        expect($response->headers->has('X-API-Version'))->toBeTrue();
        expect($response->headers->get('X-API-Version'))->not->toBeEmpty();
    });

    it('adds X-Gateway-Timing header to the response', function () {
        $request = Request::create('/api/test', 'GET');

        $response = $this->middleware->handle($request, function () {
            return new Response('OK', 200);
        });

        expect($response->headers->has('X-Gateway-Timing'))->toBeTrue();
        expect($response->headers->get('X-Gateway-Timing'))->toContain('ms');
    });

    it('removes X-Powered-By header from the response', function () {
        $request = Request::create('/api/test', 'GET');

        $response = $this->middleware->handle($request, function () {
            $resp = new Response('OK', 200);
            $resp->headers->set('X-Powered-By', 'PHP/8.4');

            return $resp;
        });

        expect($response->headers->get('X-Powered-By'))->toBeNull();
    });

    it('passes the request through to the next handler', function () {
        $request = Request::create('/api/test', 'GET');
        $nextCalled = false;

        $response = $this->middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return new Response('OK', 200);
        });

        expect($nextCalled)->toBeTrue();
        expect($response->getStatusCode())->toBe(200);
    });

    it('works with JsonResponse objects', function () {
        $request = Request::create('/api/test', 'GET');

        $response = $this->middleware->handle($request, function () {
            return new Illuminate\Http\JsonResponse(['status' => 'ok']);
        });

        expect($response->headers->has('X-Request-Id'))->toBeTrue();
        expect($response->headers->has('X-API-Version'))->toBeTrue();
        expect($response->headers->has('X-Gateway-Timing'))->toBeTrue();
    });
});
