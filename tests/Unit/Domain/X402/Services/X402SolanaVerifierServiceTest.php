<?php

declare(strict_types=1);

use App\Domain\X402\Services\X402SolanaVerifierService;

uses(Tests\TestCase::class);

describe('X402SolanaVerifierService', function (): void {
    beforeEach(function (): void {
        config([
            'x402.assets.solana:mainnet.USDC' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
            'x402.assets.solana:devnet.USDC'  => '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU',
        ]);
    });

    it('accepts a valid payload with correct recipient, amount, mint, and expiry', function (): void {
        $verifier = new X402SolanaVerifierService();

        $result = $verifier->verify(
            payload: [
                'signature'   => bin2hex(random_bytes(64)),
                'transaction' => [
                    'from'        => 'SenderAddress111111111111111111111',
                    'to'          => 'RecipientAddress1111111111111111111',
                    'amount'      => '200000',
                    'mint'        => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                    'validBefore' => (string) (time() + 300),
                    'nonce'       => bin2hex(random_bytes(32)),
                ],
            ],
            expectedRecipient: 'RecipientAddress1111111111111111111',
            expectedAmount: '100000',
        );

        expect($result['valid'])->toBeTrue();
        expect($result['reason'])->toBeNull();
    });

    it('rejects payload with missing signature', function (): void {
        $verifier = new X402SolanaVerifierService();

        $result = $verifier->verify(
            payload: [
                'signature'   => '',
                'transaction' => [
                    'to'     => 'RecipientAddress1111111111111111111',
                    'amount' => '100000',
                ],
            ],
            expectedRecipient: 'RecipientAddress1111111111111111111',
            expectedAmount: '100000',
        );

        expect($result['valid'])->toBeFalse();
        expect($result['reason'])->toBe('Missing signature or transaction data');
    });

    it('rejects payload with empty transaction', function (): void {
        $verifier = new X402SolanaVerifierService();

        $result = $verifier->verify(
            payload: [
                'signature'   => bin2hex(random_bytes(64)),
                'transaction' => [],
            ],
            expectedRecipient: 'RecipientAddress1111111111111111111',
            expectedAmount: '100000',
        );

        expect($result['valid'])->toBeFalse();
        expect($result['reason'])->toBe('Missing signature or transaction data');
    });

    it('rejects payload with wrong recipient', function (): void {
        $verifier = new X402SolanaVerifierService();

        $result = $verifier->verify(
            payload: [
                'signature'   => bin2hex(random_bytes(64)),
                'transaction' => [
                    'to'          => 'WrongRecipient11111111111111111111',
                    'amount'      => '100000',
                    'mint'        => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                    'validBefore' => (string) (time() + 300),
                ],
            ],
            expectedRecipient: 'CorrectRecipient1111111111111111111',
            expectedAmount: '100000',
        );

        expect($result['valid'])->toBeFalse();
        expect($result['reason'])->toContain('Recipient mismatch');
    });

    it('rejects payload with insufficient amount', function (): void {
        $verifier = new X402SolanaVerifierService();

        $result = $verifier->verify(
            payload: [
                'signature'   => bin2hex(random_bytes(64)),
                'transaction' => [
                    'to'          => 'RecipientAddress1111111111111111111',
                    'amount'      => '50000',
                    'mint'        => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                    'validBefore' => (string) (time() + 300),
                ],
            ],
            expectedRecipient: 'RecipientAddress1111111111111111111',
            expectedAmount: '100000',
        );

        expect($result['valid'])->toBeFalse();
        expect($result['reason'])->toContain('less than required');
    });

    it('rejects expired payment authorization', function (): void {
        $verifier = new X402SolanaVerifierService();

        $result = $verifier->verify(
            payload: [
                'signature'   => bin2hex(random_bytes(64)),
                'transaction' => [
                    'to'          => 'RecipientAddress1111111111111111111',
                    'amount'      => '100000',
                    'mint'        => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                    'validBefore' => (string) (time() - 10),
                ],
            ],
            expectedRecipient: 'RecipientAddress1111111111111111111',
            expectedAmount: '100000',
        );

        expect($result['valid'])->toBeFalse();
        expect($result['reason'])->toBe('Payment authorization has expired');
    });

    it('rejects payload with invalid USDC mint address', function (): void {
        $verifier = new X402SolanaVerifierService();

        $result = $verifier->verify(
            payload: [
                'signature'   => bin2hex(random_bytes(64)),
                'transaction' => [
                    'to'          => 'RecipientAddress1111111111111111111',
                    'amount'      => '100000',
                    'mint'        => 'InvalidMintAddress1111111111111111111',
                    'validBefore' => (string) (time() + 300),
                ],
            ],
            expectedRecipient: 'RecipientAddress1111111111111111111',
            expectedAmount: '100000',
        );

        expect($result['valid'])->toBeFalse();
        expect($result['reason'])->toContain('Invalid USDC mint address');
    });

    it('accepts devnet USDC mint address', function (): void {
        $verifier = new X402SolanaVerifierService();

        $result = $verifier->verify(
            payload: [
                'signature'   => bin2hex(random_bytes(64)),
                'transaction' => [
                    'from'        => 'SenderAddress111111111111111111111',
                    'to'          => 'RecipientAddress1111111111111111111',
                    'amount'      => '100000',
                    'mint'        => '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU',
                    'validBefore' => (string) (time() + 300),
                    'nonce'       => bin2hex(random_bytes(32)),
                ],
            ],
            expectedRecipient: 'RecipientAddress1111111111111111111',
            expectedAmount: '100000',
        );

        expect($result['valid'])->toBeTrue();
        expect($result['reason'])->toBeNull();
    });

    it('accepts exact amount match', function (): void {
        $verifier = new X402SolanaVerifierService();

        $result = $verifier->verify(
            payload: [
                'signature'   => bin2hex(random_bytes(64)),
                'transaction' => [
                    'from'        => 'SenderAddress111111111111111111111',
                    'to'          => 'RecipientAddress1111111111111111111',
                    'amount'      => '100000',
                    'mint'        => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                    'validBefore' => (string) (time() + 300),
                    'nonce'       => bin2hex(random_bytes(32)),
                ],
            ],
            expectedRecipient: 'RecipientAddress1111111111111111111',
            expectedAmount: '100000',
        );

        expect($result['valid'])->toBeTrue();
        expect($result['reason'])->toBeNull();
    });
});
