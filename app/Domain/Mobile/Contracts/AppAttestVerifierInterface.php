<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Contracts;

use App\Domain\Mobile\DataObjects\AppAttestVerificationResult;

interface AppAttestVerifierInterface
{
    public function verifyAttestation(string $attestationObject, string $challenge, string $keyId): AppAttestVerificationResult;

    /**
     * @param  string  $credentialPublicKeyHex  Uncompressed P-256 public point: 04 + hex(X) + hex(Y) (130 hex chars)
     * @param  int|null  $lastAcceptedSignCount  Floor from last successful assertion, or attestation counter before first assertion
     */
    public function verifyAssertion(
        string $assertion,
        string $challenge,
        string $keyId,
        string $credentialPublicKeyHex,
        ?int $lastAcceptedSignCount = null,
    ): AppAttestVerificationResult;
}
