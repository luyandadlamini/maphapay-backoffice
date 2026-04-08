<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Contracts;

use App\Domain\Mobile\DataObjects\AppAttestVerificationResult;

interface AppAttestVerifierInterface
{
    public function verifyAttestation(string $attestationObject, string $challenge, string $keyId): AppAttestVerificationResult;

    public function verifyAssertion(string $assertion, string $challenge, string $keyId, string $publicKey): AppAttestVerificationResult;
}
