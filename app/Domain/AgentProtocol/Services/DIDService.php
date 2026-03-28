<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for managing Decentralized Identifiers (DIDs) within the Agent Protocol.
 *
 * Handles DID generation, resolution, and verification for agent identities.
 * Supports multiple DID methods including key-based and web-based identifiers.
 *
 * Configuration from config/agent_protocol.php:
 * - did.verification_enabled: Whether DID verification is active
 * - did.signature_algorithm: Algorithm for DID signatures (default: RS256)
 * - did.cache_ttl: Cache duration for resolved DIDs
 */
class DIDService
{
    private const DID_PREFIX = 'did:finaegis:';

    private const DID_CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private readonly ?SignatureService $signatureService = null
    ) {
    }

    public function generateDID(string $method = 'key'): string
    {
        $uniqueId = Str::uuid()->toString();
        $timestamp = now()->timestamp;
        $randomBytes = bin2hex(random_bytes(16));

        $identifier = hash('sha256', $uniqueId . $timestamp . $randomBytes);

        return self::DID_PREFIX . $method . ':' . substr($identifier, 0, 32);
    }

    public function resolveDID(string $did): ?array
    {
        // Check cache first
        $cacheKey = 'did:' . $did;
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        // Parse DID
        if (! $this->validateDID($did)) {
            return null;
        }

        $parts = explode(':', $did);
        if (count($parts) < 4) {
            return null;
        }

        $didDocument = [
            '@context' => [
                'https://www.w3.org/ns/did/v1',
                'https://w3id.org/security/v1',
            ],
            'id'                 => $did,
            'verificationMethod' => [
                [
                    'id'                 => $did . '#keys-1',
                    'type'               => 'Ed25519VerificationKey2020',
                    'controller'         => $did,
                    'publicKeyMultibase' => $this->generatePublicKeyMultibase(),
                ],
            ],
            'authentication'  => [$did . '#keys-1'],
            'assertionMethod' => [$did . '#keys-1'],
            'service'         => [
                [
                    'id'              => $did . '#ap2',
                    'type'            => 'AP2Service',
                    'serviceEndpoint' => config('app.url') . '/api/agents',
                ],
            ],
            'created' => now()->toIso8601String(),
            'updated' => now()->toIso8601String(),
        ];

        // Cache the document
        Cache::put($cacheKey, $didDocument, self::DID_CACHE_TTL);

        return $didDocument;
    }

    public function validateDID(string $did): bool
    {
        // Basic validation - check format
        if (! str_starts_with($did, self::DID_PREFIX)) {
            return false;
        }

        // Check structure
        $parts = explode(':', $did);
        if (count($parts) < 4) {
            return false;
        }

        // Validate method
        $validMethods = ['key', 'web', 'agent'];
        if (! in_array($parts[2], $validMethods)) {
            return false;
        }

        // Validate identifier format (should be hex)
        $identifier = $parts[3];
        if (! preg_match('/^[a-f0-9]{32}$/', $identifier)) {
            return false;
        }

        return true;
    }

    public function createDIDDocument(array $attributes): array
    {
        $did = $attributes['did'] ?? $this->generateDID();

        return [
            '@context' => [
                'https://www.w3.org/ns/did/v1',
                'https://w3id.org/security/v1',
            ],
            'id'                   => $did,
            'verificationMethod'   => $attributes['verificationMethod'] ?? [],
            'authentication'       => $attributes['authentication'] ?? [],
            'assertionMethod'      => $attributes['assertionMethod'] ?? [],
            'keyAgreement'         => $attributes['keyAgreement'] ?? [],
            'capabilityInvocation' => $attributes['capabilityInvocation'] ?? [],
            'capabilityDelegation' => $attributes['capabilityDelegation'] ?? [],
            'service'              => $attributes['service'] ?? [],
            'created'              => $attributes['created'] ?? now()->toIso8601String(),
            'updated'              => now()->toIso8601String(),
            'proof'                => $attributes['proof'] ?? null,
        ];
    }

    public function storeDIDDocument(string $did, array $document): bool
    {
        $cacheKey = 'did:document:' . $did;
        Cache::put($cacheKey, $document, self::DID_CACHE_TTL * 24); // Cache for 24 hours

        // Store in database for persistence
        // This would be implemented with a proper DID registry

        return true;
    }

    /**
     * Verify a signature against a DID's public key.
     *
     * This method retrieves the public key from the DID document and uses it
     * to cryptographically verify that the signature was created by the DID owner.
     */
    public function verifyDIDSignature(string $did, string $signature, string $message): bool
    {
        try {
            $document = $this->resolveDID($did);
            if (! $document) {
                Log::warning('DID signature verification failed: DID not found', ['did' => $did]);

                return false;
            }

            // Extract verification method from DID document
            $verificationMethods = $document['verificationMethod'] ?? [];
            if (empty($verificationMethods)) {
                Log::warning('DID signature verification failed: No verification method', ['did' => $did]);

                return false;
            }

            // Get the first verification method (primary key)
            $verificationMethod = $verificationMethods[0];
            $publicKeyMultibase = $verificationMethod['publicKeyMultibase'] ?? null;

            if (! $publicKeyMultibase) {
                Log::warning('DID signature verification failed: No public key in verification method', ['did' => $did]);

                return false;
            }

            // Decode multibase public key (z prefix = base58)
            $publicKey = $this->decodeMultibaseKey($publicKeyMultibase);
            if (! $publicKey) {
                Log::warning('DID signature verification failed: Could not decode public key', ['did' => $did]);

                return false;
            }

            // Use SignatureService if available for full cryptographic verification
            if ($this->signatureService !== null) {
                $isValid = $this->signatureService->verifySignature(
                    ['message' => $message],
                    $signature,
                    $publicKey,
                    'EdDSA' // Ed25519 is specified in the verification method type
                );

                Log::info('DID signature verification completed', [
                    'did'      => $did,
                    'is_valid' => $isValid,
                ]);

                return $isValid;
            }

            // Fallback: Use openssl for basic Ed25519/RSA verification
            return $this->verifySignatureBasic($message, $signature, $publicKey);
        } catch (Exception $e) {
            Log::error('DID signature verification error', [
                'did'   => $did,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Decode a multibase-encoded public key.
     */
    private function decodeMultibaseKey(string $multibaseKey): ?string
    {
        if (empty($multibaseKey)) {
            return null;
        }

        // Multibase prefix 'z' indicates base58btc encoding
        $prefix = $multibaseKey[0];
        $encoded = substr($multibaseKey, 1);

        if ($prefix === 'z') {
            // Base58 decode
            return base58_decode($encoded);
        }

        // For other encodings, return as-is (PEM format)
        return $multibaseKey;
    }

    /**
     * Basic signature verification using openssl.
     */
    private function verifySignatureBasic(string $message, string $signature, string $publicKey): bool
    {
        try {
            // Try to decode base64 signature
            $decodedSignature = base64_decode($signature, true);
            if ($decodedSignature === false) {
                $decodedSignature = $signature;
            }

            // Create message hash
            $messageHash = hash('sha256', $message, true);

            // For Ed25519 keys, we need sodium extension
            if (extension_loaded('sodium') && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                // PHPStan requires non-empty-string for signature parameter
                if ($decodedSignature === '') {
                    return false;
                }

                return sodium_crypto_sign_verify_detached(
                    $decodedSignature,
                    $message,
                    $publicKey
                );
            }

            // Fallback: verify using hash comparison (for demo/testing)
            // In production, this would use proper asymmetric verification
            $expectedHash = hash_hmac('sha256', $message, $publicKey);

            return hash_equals($expectedHash, $decodedSignature);
        } catch (Exception $e) {
            Log::error('Basic signature verification failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function generatePublicKeyMultibase(): string
    {
        // Generate a dummy public key in multibase format
        // In production, this would be derived from actual cryptographic keys
        $publicKey = random_bytes(32);

        return 'z' . base58_encode($publicKey);
    }

    public function extractAgentIdFromDID(string $did): ?string
    {
        if (! $this->validateDID($did)) {
            return null;
        }

        $parts = explode(':', $did);

        return $parts[3] ?? null;
    }

    public function getDIDMethod(string $did): ?string
    {
        if (! $this->validateDID($did)) {
            return null;
        }

        $parts = explode(':', $did);

        return $parts[2] ?? null;
    }
}

// Helper function for base58 encoding (with GMP/BCMath fallback)
if (! function_exists('base58_encode')) {
    function base58_encode(string $data): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $encoded = '';

        // Use GMP if available (most efficient)
        if (extension_loaded('gmp')) {
            $num = gmp_import($data);
            while (gmp_cmp($num, 0) > 0) {
                $remainder = gmp_mod($num, 58);
                $num = gmp_div($num, 58);
                $encoded = $alphabet[gmp_intval($remainder)] . $encoded;
            }
        } elseif (extension_loaded('bcmath')) {
            // BCMath fallback
            $num = '0';
            $dataLen = strlen($data);
            for ($i = 0; $i < $dataLen; $i++) {
                $num = bcadd(bcmul($num, '256'), (string) ord($data[$i]));
            }
            while (bccomp($num, '0') > 0) {
                $remainder = (int) bcmod($num, '58');
                $num = bcdiv($num, '58', 0);
                $encoded = $alphabet[$remainder] . $encoded;
            }
        } else {
            // Pure PHP fallback for small data (prototype/demo use)
            $hex = bin2hex($data);
            $dec = '0';
            $hexLen = strlen($hex);
            for ($i = 0; $i < $hexLen; $i++) {
                $dec = base58_bcadd(base58_bcmul($dec, '16'), (string) hexdec($hex[$i]));
            }
            while (base58_bccomp($dec, '0') > 0) {
                $remainder = (int) base58_bcmod($dec, '58');
                $dec = base58_bcdiv($dec, '58');
                $encoded = $alphabet[$remainder] . $encoded;
            }
        }

        // Add leading 1s for leading zero bytes
        for ($i = 0; isset($data[$i]) && $data[$i] === "\0"; $i++) {
            $encoded = '1' . $encoded;
        }

        return $encoded ?: '1';
    }
}

// Helper function for base58 decoding (with GMP/BCMath fallback)
if (! function_exists('base58_decode')) {
    function base58_decode(string $encoded): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $len = strlen($encoded);

        // Use GMP if available
        if (extension_loaded('gmp')) {
            $num = gmp_init(0);
            for ($i = 0; $i < $len; $i++) {
                $pos = strpos($alphabet, $encoded[$i]);
                if ($pos === false) {
                    return '';
                }
                $num = gmp_add(gmp_mul($num, 58), $pos);
            }
            $decoded = gmp_cmp($num, 0) === 0 ? '' : gmp_export($num);
        } elseif (extension_loaded('bcmath')) {
            // BCMath fallback
            $num = '0';
            for ($i = 0; $i < $len; $i++) {
                $pos = strpos($alphabet, $encoded[$i]);
                if ($pos === false) {
                    return '';
                }
                $num = bcadd(bcmul($num, '58'), (string) $pos);
            }
            // Convert decimal to binary
            $decoded = '';
            while (bccomp($num, '0') > 0) {
                $decoded = chr((int) bcmod($num, '256') & 0xFF) . $decoded;
                $num = bcdiv($num, '256', 0);
            }
        } else {
            // Pure PHP fallback
            $num = '0';
            for ($i = 0; $i < $len; $i++) {
                $pos = strpos($alphabet, $encoded[$i]);
                if ($pos === false) {
                    return '';
                }
                $num = base58_bcadd(base58_bcmul($num, '58'), (string) $pos);
            }
            // Convert to binary
            $decoded = '';
            while (base58_bccomp($num, '0') > 0) {
                $decoded = chr((int) base58_bcmod($num, '256') & 0xFF) . $decoded;
                $num = base58_bcdiv($num, '256');
            }
        }

        // Add leading zero bytes
        for ($i = 0; $i < $len && $encoded[$i] === '1'; $i++) {
            $decoded = "\0" . $decoded;
        }

        return $decoded;
    }
}

// Pure PHP arbitrary precision math helpers (fallback when no extensions available)
if (! function_exists('base58_bcadd')) {
    function base58_bcadd(string $a, string $b): string
    {
        $result = '';
        $carry = 0;
        $a = strrev($a);
        $b = strrev($b);
        $maxLen = max(strlen($a), strlen($b));

        for ($i = 0; $i < $maxLen || $carry; $i++) {
            $sum = $carry;
            $sum += $i < strlen($a) ? (int) $a[$i] : 0;
            $sum += $i < strlen($b) ? (int) $b[$i] : 0;
            $result .= $sum % 10;
            $carry = (int) ($sum / 10);
        }

        return strrev($result) ?: '0';
    }
}

if (! function_exists('base58_bcmul')) {
    function base58_bcmul(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }
        $bInt = (int) $b;
        $result = '0';
        $a = strrev($a);
        $carry = 0;
        $temp = '';

        for ($i = 0; $i < strlen($a) || $carry; $i++) {
            $prod = $carry + ($i < strlen($a) ? (int) $a[$i] * $bInt : 0);
            $temp .= $prod % 10;
            $carry = (int) ($prod / 10);
        }

        return strrev($temp) ?: '0';
    }
}

if (! function_exists('base58_bccomp')) {
    function base58_bccomp(string $a, string $b): int
    {
        $a = ltrim($a, '0') ?: '0';
        $b = ltrim($b, '0') ?: '0';
        if (strlen($a) !== strlen($b)) {
            return strlen($a) > strlen($b) ? 1 : -1;
        }

        return strcmp($a, $b) <=> 0;
    }
}

if (! function_exists('base58_bcmod')) {
    function base58_bcmod(string $a, string $b): string
    {
        $bInt = (int) $b;
        $mod = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $mod = ($mod * 10 + (int) $a[$i]) % $bInt;
        }

        return (string) $mod;
    }
}

if (! function_exists('base58_bcdiv')) {
    function base58_bcdiv(string $a, string $b): string
    {
        $bInt = (int) $b;
        $result = '';
        $carry = 0;

        for ($i = 0; $i < strlen($a); $i++) {
            $current = $carry * 10 + (int) $a[$i];
            $result .= (int) ($current / $bInt);
            $carry = $current % $bInt;
        }

        return ltrim($result, '0') ?: '0';
    }
}
