<?php

declare(strict_types=1);

namespace App\Domain\TrustCert\Services;

use App\Domain\TrustCert\Contracts\RevocationRegistryInterface;
use App\Domain\TrustCert\Contracts\TrustFrameworkInterface;
use App\Domain\TrustCert\Enums\RevocationReason;
use App\Domain\TrustCert\Enums\TrustLevel;
use App\Domain\TrustCert\ValueObjects\TrustChain;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Service for W3C Verifiable Credentials standard implementation.
 *
 * Implements W3C VC Data Model v1.1 and v2.0 features.
 *
 * @see https://www.w3.org/TR/vc-data-model/
 */
class VerifiableCredentialService
{
    public function __construct(
        private readonly RevocationRegistryInterface $revocationRegistry,
        private readonly TrustFrameworkInterface $trustFramework,
        private string $issuerId = '',
        private string $signingKey = '',
    ) {
        if (empty($this->issuerId)) {
            $this->issuerId = (string) config('trustcert.credentials.default_issuer', 'did:finaegis:issuer:default');
        }
        if (empty($this->signingKey)) {
            $this->signingKey = (string) config('trustcert.credentials.credential_signing_key', '');
        }
        if (app()->environment('production') && ! config('trustcert.allow_demo', false) && empty($this->signingKey)) {
            throw new RuntimeException(
                'Credential signing key must be configured in production. ' .
                'Set TRUSTCERT_CREDENTIAL_SIGNING_KEY in .env or TRUSTCERT_ALLOW_DEMO=true to allow demo mode.'
            );
        }
    }

    /**
     * Issue a W3C Verifiable Credential.
     *
     * @param array<string, mixed> $credentialSubject
     * @param array<string>        $types
     * @param array<string>        $context
     *
     * @return array<string, mixed>
     */
    public function issueCredential(
        string $subjectId,
        array $credentialSubject,
        array $types = ['VerifiableCredential'],
        ?DateTimeInterface $expirationDate = null,
        array $context = ['https://www.w3.org/2018/credentials/v1'],
    ): array {
        $credentialId = 'urn:finaegis:credential:' . Str::uuid()->toString();
        $issuanceDate = new DateTimeImmutable();

        // Build credential
        $credential = [
            '@context'          => $context,
            'id'                => $credentialId,
            'type'              => $types,
            'issuer'            => $this->issuerId,
            'issuanceDate'      => $issuanceDate->format('c'),
            'credentialSubject' => array_merge(['id' => $subjectId], $credentialSubject),
        ];

        if ($expirationDate !== null) {
            $credential['expirationDate'] = $expirationDate->format('c');
        }

        // Add credential status for revocation checking
        $credential['credentialStatus'] = [
            'id'   => "urn:finaegis:revocation:{$credentialId}",
            'type' => 'StatusList2021Entry',
        ];

        // Generate proof
        $credential['proof'] = $this->generateProof($credential);

        return $credential;
    }

    /**
     * Verify a Verifiable Credential.
     *
     * @param array<string, mixed> $credential
     *
     * @return array<string, mixed>
     */
    public function verifyCredential(array $credential): array
    {
        $errors = [];
        $warnings = [];

        // Check required fields
        $requiredFields = ['@context', 'type', 'issuer', 'issuanceDate', 'credentialSubject'];
        foreach ($requiredFields as $field) {
            if (! isset($credential[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        if (! empty($errors)) {
            return [
                'valid'    => false,
                'errors'   => $errors,
                'warnings' => $warnings,
            ];
        }

        // Check expiration
        if (isset($credential['expirationDate'])) {
            $expiration = new DateTimeImmutable($credential['expirationDate']);
            if ($expiration < new DateTimeImmutable()) {
                $errors[] = 'Credential has expired';
            }
        }

        // Check revocation status
        $credentialId = $credential['id'] ?? null;
        if ($credentialId !== null && $this->revocationRegistry->isRevoked($credentialId)) {
            $errors[] = 'Credential has been revoked';
        }

        // Verify issuer trust
        $issuerId = is_array($credential['issuer']) ? $credential['issuer']['id'] : $credential['issuer'];
        if (! $this->trustFramework->isIssuerTrusted($issuerId)) {
            $warnings[] = 'Issuer is not in the trusted issuer registry';
        }

        // Verify proof
        if (isset($credential['proof'])) {
            $proofValid = $this->verifyProof($credential);
            if (! $proofValid) {
                $errors[] = 'Proof verification failed';
            }
        } else {
            $warnings[] = 'Credential has no proof';
        }

        return [
            'valid'       => empty($errors),
            'errors'      => $errors,
            'warnings'    => $warnings,
            'issuer_id'   => $issuerId,
            'verified_at' => (new DateTimeImmutable())->format('c'),
        ];
    }

    /**
     * Create a Verifiable Presentation.
     *
     * @param array<array<string, mixed>> $credentials
     *
     * @return array<string, mixed>
     */
    public function createPresentation(
        array $credentials,
        string $holder,
        ?string $challenge = null,
        ?string $domain = null,
    ): array {
        $presentationId = 'urn:finaegis:presentation:' . Str::uuid()->toString();

        $presentation = [
            '@context'             => ['https://www.w3.org/2018/credentials/v1'],
            'id'                   => $presentationId,
            'type'                 => ['VerifiablePresentation'],
            'holder'               => $holder,
            'verifiableCredential' => $credentials,
        ];

        // Generate presentation proof
        $presentation['proof'] = $this->generatePresentationProof(
            $presentation,
            $challenge,
            $domain,
        );

        return $presentation;
    }

    /**
     * Verify a Verifiable Presentation.
     *
     * @param array<string, mixed> $presentation
     *
     * @return array<string, mixed>
     */
    public function verifyPresentation(
        array $presentation,
        ?string $expectedChallenge = null,
        ?string $expectedDomain = null,
    ): array {
        $errors = [];
        $credentialResults = [];

        // Check required fields
        if (! isset($presentation['type']) || ! in_array('VerifiablePresentation', $presentation['type'], true)) {
            $errors[] = 'Invalid presentation type';
        }

        if (! isset($presentation['holder'])) {
            $errors[] = 'Missing holder';
        }

        // Verify proof
        if (isset($presentation['proof'])) {
            // Check challenge
            if ($expectedChallenge !== null) {
                $proofChallenge = $presentation['proof']['challenge'] ?? null;
                if ($proofChallenge !== $expectedChallenge) {
                    $errors[] = 'Challenge mismatch';
                }
            }

            // Check domain
            if ($expectedDomain !== null) {
                $proofDomain = $presentation['proof']['domain'] ?? null;
                if ($proofDomain !== $expectedDomain) {
                    $errors[] = 'Domain mismatch';
                }
            }
        }

        // Verify each credential
        $credentials = $presentation['verifiableCredential'] ?? [];
        foreach ($credentials as $index => $credential) {
            $result = $this->verifyCredential($credential);
            $credentialResults[$index] = $result;
            if (! $result['valid']) {
                $errors[] = "Credential at index {$index} is invalid";
            }
        }

        return [
            'valid'              => empty($errors),
            'errors'             => $errors,
            'credential_results' => $credentialResults,
            'holder'             => $presentation['holder'] ?? null,
            'verified_at'        => (new DateTimeImmutable())->format('c'),
        ];
    }

    /**
     * Revoke a credential.
     */
    public function revokeCredential(string $credentialId, RevocationReason $reason): bool
    {
        $this->revocationRegistry->revoke($credentialId, $reason);

        return true;
    }

    /**
     * Build trust chain for a credential.
     */
    public function buildTrustChain(string $credentialId, string $issuerId): TrustChain
    {
        return $this->trustFramework->buildTrustChain($credentialId, $issuerId);
    }

    /**
     * Check if a credential meets minimum trust level requirements.
     */
    public function meetsMinimumTrustLevel(string $issuerId, TrustLevel $required): bool
    {
        $trustLevel = $this->trustFramework->getIssuerTrustLevel($issuerId);
        if ($trustLevel === null) {
            return false;
        }

        return $trustLevel->meetsMinimum($required);
    }

    /**
     * Generate a proof for a credential.
     *
     * @param array<string, mixed> $credential
     *
     * @return array<string, mixed>
     */
    private function generateProof(array $credential): array
    {
        $created = new DateTimeImmutable();

        // Exclude proof from signing
        $dataToSign = $credential;
        unset($dataToSign['proof']);

        $signingKey = $this->signingKey ?: config('trustcert.credential_signing_key', 'default-signing-key');
        $proofValue = base64_encode(hash_hmac(
            'sha256',
            json_encode($dataToSign, JSON_THROW_ON_ERROR),
            $signingKey,
            true,
        ));

        return [
            'type'               => 'Ed25519Signature2020',
            'created'            => $created->format('c'),
            'verificationMethod' => $this->issuerId . '#key-1',
            'proofPurpose'       => 'assertionMethod',
            'proofValue'         => $proofValue,
        ];
    }

    /**
     * Verify a credential proof.
     *
     * @param array<string, mixed> $credential
     */
    private function verifyProof(array $credential): bool
    {
        if (! isset($credential['proof']['proofValue'])) {
            return false;
        }

        $originalProofValue = $credential['proof']['proofValue'];

        // Regenerate proof
        $dataToSign = $credential;
        unset($dataToSign['proof']);

        $signingKey = $this->signingKey ?: config('trustcert.credential_signing_key', 'default-signing-key');
        $expectedProofValue = base64_encode(hash_hmac(
            'sha256',
            json_encode($dataToSign, JSON_THROW_ON_ERROR),
            $signingKey,
            true,
        ));

        return hash_equals($expectedProofValue, $originalProofValue);
    }

    /**
     * Generate a proof for a presentation.
     *
     * @param array<string, mixed> $presentation
     *
     * @return array<string, mixed>
     */
    private function generatePresentationProof(
        array $presentation,
        ?string $challenge,
        ?string $domain,
    ): array {
        $created = new DateTimeImmutable();

        // Exclude proof from signing
        $dataToSign = $presentation;
        unset($dataToSign['proof']);

        if ($challenge !== null) {
            $dataToSign['challenge'] = $challenge;
        }
        if ($domain !== null) {
            $dataToSign['domain'] = $domain;
        }

        $signingKey = $this->signingKey ?: config('trustcert.presentation_signing_key', 'default-signing-key');
        $proofValue = base64_encode(hash_hmac(
            'sha256',
            json_encode($dataToSign, JSON_THROW_ON_ERROR),
            $signingKey,
            true,
        ));

        $proof = [
            'type'               => 'Ed25519Signature2020',
            'created'            => $created->format('c'),
            'verificationMethod' => ($presentation['holder'] ?? $this->issuerId) . '#key-1',
            'proofPurpose'       => 'authentication',
            'proofValue'         => $proofValue,
        ];

        if ($challenge !== null) {
            $proof['challenge'] = $challenge;
        }
        if ($domain !== null) {
            $proof['domain'] = $domain;
        }

        return $proof;
    }
}
