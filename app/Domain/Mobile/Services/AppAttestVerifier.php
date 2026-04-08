<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Services;

use App\Domain\Mobile\Contracts\AppAttestVerifierInterface;
use App\Domain\Mobile\DataObjects\AppAttestVerificationResult;

class AppAttestVerifier implements AppAttestVerifierInterface
{
    public function __construct(
        private readonly AppleAttestationVerifier $appleAttestationVerifier,
    ) {
    }

    public function verifyAttestation(string $attestationObject, string $challenge, string $keyId): AppAttestVerificationResult
    {
        if ($keyId === '') {
            return AppAttestVerificationResult::failure('missing_key_id');
        }

        if (! $this->appleAttestationVerifier->verify($attestationObject, $challenge)) {
            return AppAttestVerificationResult::failure('apple_attestation_verification_failed');
        }

        return AppAttestVerificationResult::success([
            'key_id' => $keyId,
            'mode'   => 'baseline_apple_attestation_verifier',
        ], 'attestation_verified');
    }

    public function verifyAssertion(string $assertion, string $challenge, string $keyId, string $publicKey): AppAttestVerificationResult
    {
        return AppAttestVerificationResult::failure('assertion_cryptographic_verification_not_implemented');
    }
}
