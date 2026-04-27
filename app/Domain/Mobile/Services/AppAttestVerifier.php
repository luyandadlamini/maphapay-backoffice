<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Services;

use App\Domain\Mobile\Contracts\AppAttestVerifierInterface;
use App\Domain\Mobile\DataObjects\AppAttestVerificationResult;

class AppAttestVerifier implements AppAttestVerifierInterface
{
    private readonly AppleAppAttestCrypto $crypto;

    public function __construct(
        private readonly AppleAttestationVerifier $appleAttestationVerifier,
        ?AppleAppAttestCrypto $crypto = null,
    ) {
        $this->crypto = $crypto ?? new AppleAppAttestCrypto();
    }

    public function verifyAttestation(string $attestationObject, string $challenge, string $keyId): AppAttestVerificationResult
    {
        if ($keyId === '') {
            return AppAttestVerificationResult::failure('missing_key_id');
        }

        if (! $this->appleAttestationVerifier->verify($attestationObject, $challenge)) {
            return AppAttestVerificationResult::failure('apple_attestation_verification_failed');
        }

        $teamId = (string) config('mobile.attestation.apple.team_id', '');
        $bundleId = (string) config('mobile.attestation.apple.bundle_id', '');

        if ($teamId === '' || $bundleId === '') {
            return AppAttestVerificationResult::failure('apple_team_or_bundle_not_configured');
        }

        $expectedRpIdHash = hash('sha256', $teamId . '.' . $bundleId, true);
        $publicKeyHex = $this->crypto->extractCredentialPublicKeyHexFromAttestation(
            $attestationObject,
            $expectedRpIdHash,
        );

        if ($publicKeyHex === null) {
            return AppAttestVerificationResult::failure('credential_public_key_extraction_failed');
        }

        $attestationSignCount = $this->crypto->extractSignCountFromAttestation($attestationObject);

        return AppAttestVerificationResult::success([
            'key_id'                     => $keyId,
            'mode'                       => 'apple_app_attest_crypto',
            'credential_public_key_hex' => $publicKeyHex,
            'public_key'                 => $publicKeyHex,
            'attestation_sign_count'     => $attestationSignCount,
        ], 'attestation_verified');
    }

    public function verifyAssertion(
        string $assertion,
        string $challenge,
        string $keyId,
        string $credentialPublicKeyHex,
        ?int $lastAcceptedSignCount = null,
    ): AppAttestVerificationResult {
        $result = $this->crypto->verifyAssertion(
            $assertion,
            $challenge,
            $credentialPublicKeyHex,
            $lastAcceptedSignCount,
        );

        if (! $result['verified']) {
            return AppAttestVerificationResult::failure(
                (string) ($result['reason'] ?? 'assertion_verification_failed'),
                $result['metadata'] ?? [],
            );
        }

        return AppAttestVerificationResult::success(
            $result['metadata'] ?? [],
            (string) ($result['reason'] ?? 'assertion_verified'),
        );
    }
}
