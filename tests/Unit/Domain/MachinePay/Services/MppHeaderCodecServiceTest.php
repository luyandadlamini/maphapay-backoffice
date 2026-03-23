<?php

declare(strict_types=1);

use App\Domain\MachinePay\DataObjects\MppChallenge;
use App\Domain\MachinePay\DataObjects\MppCredential;
use App\Domain\MachinePay\DataObjects\MppReceipt;
use App\Domain\MachinePay\Services\MppHeaderCodecService;

describe('MppHeaderCodecService', function (): void {
    it('encodes challenge for WWW-Authenticate header', function (): void {
        $codec = new MppHeaderCodecService();
        $challenge = new MppChallenge(
            id: 'ch_test',
            realm: 'test',
            intent: 'charge',
            resourceId: 'test',
            amountCents: 50,
            currency: 'USD',
            availableRails: ['stripe'],
            nonce: 'nonce',
            expiresAt: '2099-12-31T23:59:59Z',
        );

        $header = $codec->encodeChallenge($challenge);
        expect($header)->toStartWith('Payment ');
    });

    it('decodes credential from Authorization header', function (): void {
        $codec = new MppHeaderCodecService();
        $cred = new MppCredential(
            challengeId: 'ch_test',
            rail: 'stripe',
            proofOfPayment: ['spt' => 'spt_demo'],
            payerIdentifier: 'agent_001',
            timestamp: '2026-01-01T00:00:00Z',
        );

        $header = 'Payment ' . $cred->toBase64Url();
        $decoded = $codec->decodeCredential($header);

        expect($decoded->challengeId)->toBe('ch_test');
        expect($decoded->rail)->toBe('stripe');
    });

    it('encodes receipt for Payment-Receipt header', function (): void {
        $codec = new MppHeaderCodecService();
        $receipt = new MppReceipt(
            receiptId: 'rcpt_test',
            challengeId: 'ch_test',
            rail: 'stripe',
            settlementReference: 'pi_test',
            settledAt: '2026-01-01T00:00:00Z',
            amountCents: 50,
            currency: 'USD',
        );

        $header = $codec->encodeReceipt($receipt);
        expect($header)->toBeString();
        expect($header)->not->toBeEmpty();
    });
});
