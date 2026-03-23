<?php

declare(strict_types=1);

use App\Domain\MachinePay\DataObjects\MonetizedResourceConfig;
use App\Domain\MachinePay\DataObjects\MppChallenge;
use App\Domain\MachinePay\DataObjects\MppCredential;
use App\Domain\MachinePay\DataObjects\MppReceipt;
use App\Domain\MachinePay\DataObjects\ProblemDetail;

describe('MppChallenge', function (): void {
    it('serializes to array and back', function (): void {
        $challenge = new MppChallenge(
            id: 'ch_test123',
            realm: 'example.com',
            intent: 'charge',
            resourceId: 'GET:api/v1/data',
            amountCents: 50,
            currency: 'USD',
            availableRails: ['stripe', 'tempo'],
            nonce: 'abc123',
            expiresAt: '2026-12-31T23:59:59Z',
            hmac: 'hmac_hash',
            description: 'Premium data',
        );

        $array = $challenge->toArray();
        $restored = MppChallenge::fromArray($array);

        expect($restored->id)->toBe('ch_test123');
        expect($restored->realm)->toBe('example.com');
        expect($restored->amountCents)->toBe(50);
        expect($restored->availableRails)->toBe(['stripe', 'tempo']);
    });

    it('encodes and decodes base64url', function (): void {
        $challenge = new MppChallenge(
            id: 'ch_b64',
            realm: 'test.local',
            intent: 'charge',
            resourceId: 'GET:test',
            amountCents: 100,
            currency: 'USD',
            availableRails: ['stripe'],
            nonce: 'nonce123',
            expiresAt: '2026-12-31T23:59:59Z',
        );

        $encoded = $challenge->toBase64Url();
        $decoded = MppChallenge::fromBase64Url($encoded);

        expect($decoded->id)->toBe('ch_b64');
        expect($decoded->amountCents)->toBe(100);
    });

    it('builds HMAC input string', function (): void {
        $challenge = new MppChallenge(
            id: 'ch_hmac',
            realm: 'api.example.com',
            intent: 'charge',
            resourceId: 'premium_data',
            amountCents: 50,
            currency: 'USD',
            availableRails: ['stripe'],
            nonce: 'nonce_value',
            expiresAt: '2026-01-01T00:00:00Z',
        );

        $input = $challenge->buildHmacInput();
        expect($input)->toContain('api.example.com');
        expect($input)->toContain('charge');
        expect($input)->toContain('nonce_value');
    });

    it('detects expiry', function (): void {
        $expired = new MppChallenge(
            id: 'ch_old',
            realm: 'test',
            intent: 'charge',
            resourceId: 'test',
            amountCents: 10,
            currency: 'USD',
            availableRails: [],
            nonce: 'n',
            expiresAt: '2020-01-01T00:00:00Z',
        );

        expect($expired->isExpired())->toBeTrue();

        $valid = new MppChallenge(
            id: 'ch_new',
            realm: 'test',
            intent: 'charge',
            resourceId: 'test',
            amountCents: 10,
            currency: 'USD',
            availableRails: [],
            nonce: 'n',
            expiresAt: '2099-12-31T23:59:59Z',
        );

        expect($valid->isExpired())->toBeFalse();
    });
});

describe('MppCredential', function (): void {
    it('serializes to array and base64url', function (): void {
        $cred = new MppCredential(
            challengeId: 'ch_test',
            rail: 'stripe',
            proofOfPayment: ['spt' => 'spt_demo_123'],
            payerIdentifier: 'agent_001',
            timestamp: '2026-01-01T00:00:00Z',
        );

        $array = $cred->toArray();
        expect($array['rail'])->toBe('stripe');

        $encoded = $cred->toBase64Url();
        $decoded = MppCredential::fromBase64Url($encoded);
        expect($decoded->challengeId)->toBe('ch_test');
    });
});

describe('MppReceipt', function (): void {
    it('creates and serializes', function (): void {
        $receipt = new MppReceipt(
            receiptId: 'rcpt_123',
            challengeId: 'ch_test',
            rail: 'stripe',
            settlementReference: 'pi_123',
            settledAt: '2026-01-01T00:00:00Z',
            amountCents: 100,
            currency: 'USD',
        );

        expect($receipt->isSuccess())->toBeTrue();
        expect($receipt->toArray()['receipt_id'])->toBe('rcpt_123');
    });
});

describe('ProblemDetail', function (): void {
    it('creates payment-required problem detail', function (): void {
        $problem = ProblemDetail::paymentRequired('Payment needed', 'ch_123');

        expect($problem->status)->toBe(402);
        expect($problem->title)->toBe('Payment Required');
        expect($problem->toArray()['challenge_id'])->toBe('ch_123');
    });

    it('creates settlement-failed problem detail', function (): void {
        $problem = ProblemDetail::settlementFailed('Rail unavailable');

        expect($problem->status)->toBe(402);
        expect($problem->toArray()['detail'])->toContain('Rail unavailable');
    });

    it('creates rail-unsupported problem detail', function (): void {
        $problem = ProblemDetail::railUnsupported('dogecoin');

        expect($problem->status)->toBe(400);
        expect($problem->toArray()['detail'])->toContain('dogecoin');
    });
});

describe('MonetizedResourceConfig', function (): void {
    it('creates from array', function (): void {
        $config = MonetizedResourceConfig::fromArray([
            'method'          => 'GET',
            'path'            => 'api/v1/premium',
            'amount_cents'    => 50,
            'currency'        => 'USD',
            'available_rails' => ['stripe', 'tempo'],
        ]);

        expect($config->method)->toBe('GET');
        expect($config->amountCents)->toBe(50);
        expect($config->availableRails)->toHaveCount(2);
    });
});
