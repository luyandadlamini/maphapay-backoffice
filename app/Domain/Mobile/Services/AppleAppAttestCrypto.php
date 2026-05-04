<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Services;

use CBOR\Decoder;
use CBOR\MapObject;
use CBOR\StringStream;
use Elliptic\EC;
use Elliptic\EC\Signature;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * CBOR parsing, credential public key extraction (attestation), and ES256 assertion verification
 * for Apple App Attest (DeviceCheck).
 *
 * @see https://developer.apple.com/documentation/devicecheck/validating_apps_that_connect_to_your_server
 * @see https://www.w3.org/TR/webauthn-2/#sctn-authenticator-data
 */
final class AppleAppAttestCrypto
{
    private const COSE_KEY_KTY = 1;

    private const COSE_EC2_CRV = -1;

    private const COSE_EC2_X = -2;

    private const COSE_EC2_Y = -3;

    private const COSE_KTY_EC2 = 2;

    private const COSE_CRV_P256 = 1;

    private readonly Decoder $decoder;

    public function __construct()
    {
        $this->decoder = Decoder::create();
    }

    /**
     * @return array<int|string, mixed>
     */
    public function decodeCborMap(string $binary): array
    {
        $object = $this->decoder->decode(new StringStream($binary));

        if (! $object instanceof MapObject) {
            throw new InvalidArgumentException('expected_cbor_map');
        }

        /** @var array<int|string, mixed> $normalized */
        $normalized = $object->normalize();

        return $normalized;
    }

    /**
     * Uncompressed EC P-256 public point as hex (130 chars: 04 + 32-byte X + 32-byte Y).
     */
    public function extractSignCountFromAttestation(string $attestationObjectBase64): ?int
    {
        $raw = base64_decode($attestationObjectBase64, true);

        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $map = $this->decodeCborMap($raw);
        } catch (InvalidArgumentException) {
            return null;
        }

        $authData = $map['authData'] ?? null;

        if (! is_string($authData) || strlen($authData) < 37) {
            return null;
        }

        return unpack('N', substr($authData, 33, 4))[1];
    }

    public function extractCredentialPublicKeyHexFromAttestation(
        string $attestationObjectBase64,
        string $expectedRpIdHashBinary,
    ): ?string {
        $raw = base64_decode($attestationObjectBase64, true);

        if ($raw === false || $raw === '') {
            Log::warning('App Attest: base64 decode failed or empty', [
                'input_length' => strlen($attestationObjectBase64),
                'decode_result' => $raw === false ? 'false' : 'empty',
            ]);

            return null;
        }

        try {
            $map = $this->decodeCborMap($raw);
        } catch (InvalidArgumentException $e) {
            Log::warning('App Attest: top-level CBOR decode failed', [
                'error' => $e->getMessage(),
                'raw_length' => strlen($raw),
                'raw_first_bytes_hex' => bin2hex(substr($raw, 0, 16)),
            ]);

            return null;
        }

        $authData = $map['authData'] ?? null;

        if (! is_string($authData) || strlen($authData) < 37) {
            Log::warning('App Attest: authData missing or too short', [
                'has_authData' => array_key_exists('authData', $map),
                'authData_type' => gettype($authData),
                'authData_length' => is_string($authData) ? strlen($authData) : null,
                'map_keys' => array_keys($map),
            ]);

            return null;
        }

        $actualRpIdHash = substr($authData, 0, 32);
        if ($actualRpIdHash !== $expectedRpIdHashBinary) {
            Log::warning('App Attest: authData rpIdHash mismatch during credential extraction', [
                'expected_rpIdHash_hex' => bin2hex($expectedRpIdHashBinary),
                'actual_rpIdHash_hex' => bin2hex($actualRpIdHash),
            ]);

            return null;
        }

        $flags = ord($authData[32]);

        if (($flags & 0x40) === 0) {
            Log::warning('App Attest: attestation authData missing AT flag', [
                'flags_hex' => dechex($flags),
                'flags_int' => $flags,
            ]);

            return null;
        }

        $offset = 37;

        if (strlen($authData) < $offset + 16 + 2) {
            Log::warning('App Attest: authData too short for aaguid + credIdLen', [
                'authData_length' => strlen($authData),
                'minimum_required' => $offset + 16 + 2,
            ]);

            return null;
        }

        $offset += 16; // aaguid
        $credIdLen = unpack('n', substr($authData, $offset, 2))[1];
        $offset += 2;

        if ($credIdLen < 0 || strlen($authData) < $offset + $credIdLen) {
            Log::warning('App Attest: authData too short for credential ID', [
                'credIdLen' => $credIdLen,
                'authData_length' => strlen($authData),
                'offset_after_credIdLen' => $offset,
            ]);

            return null;
        }

        $offset += $credIdLen;
        $coseBytes = substr($authData, $offset);

        if ($coseBytes === '' || $coseBytes === false) {
            Log::warning('App Attest: no COSE key bytes after credential ID', [
                'offset' => $offset,
                'credIdLen' => $credIdLen,
            ]);

            return null;
        }

        try {
            $cose = $this->decodeCborMap($coseBytes);
        } catch (InvalidArgumentException $e) {
            Log::warning('App Attest: COSE key CBOR decode failed', [
                'error' => $e->getMessage(),
                'coseBytes_length' => strlen($coseBytes),
                'coseBytes_first_bytes_hex' => bin2hex(substr($coseBytes, 0, 16)),
            ]);

            return null;
        }

        $publicKeyHex = $this->coseEc2P256ToUncompressedHex($cose);

        if ($publicKeyHex === null) {
            $coseKeys = array_keys($cose);
            $coseKeysWithTypes = array_map(
                static fn ($k): string => sprintf('%s(%s)', $k, gettype($k)),
                $coseKeys,
            );
            Log::warning('App Attest: COSE key format mismatch', [
                'cose_kty' => $cose[self::COSE_KEY_KTY] ?? null,
                'cose_crv' => $cose[self::COSE_EC2_CRV] ?? null,
                'cose_has_x' => isset($cose[self::COSE_EC2_X]),
                'cose_has_y' => isset($cose[self::COSE_EC2_Y]),
                'cose_x_length' => isset($cose[self::COSE_EC2_X]) && is_string($cose[self::COSE_EC2_X]) ? strlen($cose[self::COSE_EC2_X]) : null,
                'cose_y_length' => isset($cose[self::COSE_EC2_Y]) && is_string($cose[self::COSE_EC2_Y]) ? strlen($cose[self::COSE_EC2_Y]) : null,
                'cose_keys' => $coseKeys,
                'cose_keys_with_types' => $coseKeysWithTypes,
                'expected_kty' => self::COSE_KTY_EC2,
                'expected_crv' => self::COSE_CRV_P256,
            ]);

            return null;
        }

        return $publicKeyHex;
    }

    /**
     * @param  int|null  $lastAcceptedSignCount  null when no prior assertion stored for this key
     * @return array{verified: bool, reason: string, metadata?: array<string, mixed>}
     */
    public function verifyAssertion(
        string $assertionBase64,
        string $challengePlain,
        string $credentialPublicKeyHex,
        ?int $lastAcceptedSignCount,
    ): array {
        $raw = base64_decode($assertionBase64, true);

        if ($raw === false || $raw === '') {
            return ['verified' => false, 'reason' => 'invalid_assertion_base64'];
        }

        try {
            $map = $this->decodeCborMap($raw);
        } catch (InvalidArgumentException) {
            return ['verified' => false, 'reason' => 'invalid_assertion_cbor'];
        }

        $authenticatorData = $map['authenticatorData'] ?? null;
        $signature = $map['signature'] ?? null;

        if (! is_string($authenticatorData) || ! is_string($signature)) {
            return ['verified' => false, 'reason' => 'assertion_missing_fields'];
        }

        if (strlen($authenticatorData) < 37) {
            return ['verified' => false, 'reason' => 'authenticator_data_too_short'];
        }

        $rpIdHash = substr($authenticatorData, 0, 32);
        $teamId = (string) config('mobile.attestation.apple.team_id', '');
        $bundleId = (string) config('mobile.attestation.apple.bundle_id', '');

        if ($teamId === '' || $bundleId === '') {
            return ['verified' => false, 'reason' => 'apple_team_or_bundle_not_configured'];
        }

        $expectedRpIdHash = hash('sha256', $teamId . '.' . $bundleId, true);

        if (! hash_equals($expectedRpIdHash, $rpIdHash)) {
            return ['verified' => false, 'reason' => 'assertion_rpid_hash_mismatch'];
        }

        $signCount = unpack('N', substr($authenticatorData, 33, 4))[1];

        if ($lastAcceptedSignCount !== null && $signCount <= $lastAcceptedSignCount) {
            return [
                'verified' => false,
                'reason'   => 'assertion_sign_count_stale',
                'metadata' => [
                    'sign_count' => $signCount,
                    'last_sign'  => $lastAcceptedSignCount,
                ],
            ];
        }

        $clientDataHash = hash('sha256', $challengePlain, true);
        $messageDigest = hash('sha256', $authenticatorData . $clientDataHash, true);
        // Apple App Attest appears to double-hash before signing:
        // the assertion signature validates against SHA256(messageDigest),
        // not against messageDigest directly.
        $doubleHash = hash('sha256', $messageDigest, true);
        $doubleHashHex = bin2hex($doubleHash);

        // Apple App Attest returns ASN.1 DER-encoded ECDSA signatures.
        // Convert to raw 64-byte R||S format for elliptic-php.
        $originalSigLen = strlen($signature);
        if ($originalSigLen !== 64) {
            $converted = $this->convertDerEcdsaSignatureToRaw($signature);
            if ($converted === null) {
                Log::warning('App Attest: DER signature conversion failed', [
                    'original_length' => $originalSigLen,
                    'first_bytes_hex' => bin2hex(substr($signature, 0, 8)),
                ]);
                return ['verified' => false, 'reason' => 'assertion_signature_length_invalid'];
            }
            $signature = $converted;
        }

        $rHex = bin2hex(substr($signature, 0, 32));
        $sHex = bin2hex(substr($signature, 32, 32));

        $pkHex = strtolower(preg_replace('/^0x/', '', $credentialPublicKeyHex) ?? '');

        if (strlen($pkHex) !== 130 || ! str_starts_with($pkHex, '04')) {
            return ['verified' => false, 'reason' => 'credential_public_key_hex_invalid'];
        }

        Log::info('App Attest: assertion verify details', [
            'auth_data_length' => strlen($authenticatorData),
            'client_data_hash_length' => strlen($clientDataHash),
            'message_digest_hex' => bin2hex($messageDigest),
            'double_hash_hex' => $doubleHashHex,
            'signature_original_length' => $originalSigLen,
            'signature_converted_length' => strlen($signature),
            'r_hex' => $rHex,
            's_hex' => $sHex,
            'pk_hex' => $pkHex,
        ]);

        $ok = false;

        try {
            $ec = new EC('p256');
            $key = $ec->keyFromPublic($pkHex, 'hex');
            $ok = $ec->verify($doubleHashHex, new Signature([
                'r' => $rHex,
                's' => $sHex,
            ]), $key, 'hex');
        } catch (\Throwable $e) {
            Log::warning('App Attest: elliptic-php verify exception', ['message' => $e->getMessage()]);
        }

        // Fallback: try OpenSSL if elliptic-php fails (or if PHP ext is available)
        if (! $ok && function_exists('openssl_verify')) {
            $opensslOk = $this->verifyWithOpenssl($doubleHash, $signature, $pkHex);
            if ($opensslOk === true) {
                Log::info('App Attest: OpenSSL verified where elliptic-php failed');
                $ok = true;
            } elseif ($opensslOk === false) {
                Log::info('App Attest: OpenSSL also failed');
            }
        }

        if (! $ok) {
            Log::warning('App Attest: assertion signature invalid', [
                'message_digest_hex' => bin2hex($messageDigest),
                'double_hash_hex' => $doubleHashHex,
                'r_hex' => $rHex,
                's_hex' => $sHex,
                'pk_hex_prefix' => substr($pkHex, 0, 16),
            ]);
            return ['verified' => false, 'reason' => 'assertion_signature_invalid'];
        }

        return [
            'verified' => true,
            'reason'   => 'assertion_verified',
            'metadata' => [
                'sign_count' => $signCount,
            ],
        ];
    }

    /**
     * @param  array<int|string, mixed>  $cose
     */
    private function coseEc2P256ToUncompressedHex(array $cose): ?string
    {
        $kty = $cose[self::COSE_KEY_KTY] ?? null;
        $crv = $cose[self::COSE_EC2_CRV] ?? null;
        $x = $cose[self::COSE_EC2_X] ?? null;
        $y = $cose[self::COSE_EC2_Y] ?? null;

        // cbor-php normalizes unsigned integers as numeric strings,
        // so compare loosely (string "2" == int 2).
        if ($kty != self::COSE_KTY_EC2 || $crv != self::COSE_CRV_P256) {
            return null;
        }

        if (! is_string($x) || ! is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }

        return '04' . bin2hex($x) . bin2hex($y);
    }

    /**
     * Convert ASN.1 DER-encoded ECDSA signature to raw 64-byte R||S.
     *
     * @see https://developer.apple.com/documentation/devicecheck/validating_apps_that_connect_to_your_server
     */
    private function convertDerEcdsaSignatureToRaw(string $der): ?string
    {
        if (strlen($der) < 8 || ord($der[0]) !== 0x30) {
            return null;
        }

        $totalLen = ord($der[1]);
        $offset = 2;

        // Check if length is in long form
        if ($totalLen & 0x80) {
            $numLenBytes = $totalLen & 0x7f;
            if ($numLenBytes > 2 || strlen($der) < 2 + $numLenBytes + 2) {
                return null;
            }
            $totalLen = 0;
            for ($i = 0; $i < $numLenBytes; $i++) {
                $totalLen = ($totalLen << 8) | ord($der[2 + $i]);
            }
            $offset = 2 + $numLenBytes;
        }

        if (strlen($der) < $offset + $totalLen) {
            return null;
        }

        // Parse R
        if (ord($der[$offset]) !== 0x02) {
            return null;
        }
        $rLen = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $rLen);
        // Strip leading zero if present (sign bit padding)
        if (strlen($r) > 32 && ord($r[0]) === 0x00) {
            $r = substr($r, 1);
        }
        if (strlen($r) > 32) {
            return null;
        }
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $offset += 2 + $rLen;

        // Parse S
        if (ord($der[$offset]) !== 0x02) {
            return null;
        }
        $sLen = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $sLen);
        if (strlen($s) > 32 && ord($s[0]) === 0x00) {
            $s = substr($s, 1);
        }
        if (strlen($s) > 32) {
            return null;
        }
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    /**
     * Verify an ECDSA P-256 signature using OpenSSL as a fallback.
     *
     * @param  string  $messageDigest  32-byte SHA-256 digest of the signed message
     * @param  string  $signatureRaw   64-byte raw R||S signature
     * @param  string  $publicKeyHex   130-char uncompressed hex public key (04 + X + Y)
     * @return bool|null  true = verified, false = invalid, null = OpenSSL error
     */
    private function verifyWithOpenssl(string $messageDigest, string $signatureRaw, string $publicKeyHex): ?bool
    {
        try {
            // Build uncompressed point DER: 0x04 <65 bytes>
            $pointDer = hex2bin(substr($publicKeyHex, 2));
            if ($pointDer === false) {
                return null;
            }

            // Build SubjectPublicKeyInfo for P-256
            // AlgorithmIdentifier for EC P-256:
            // SEQUENCE { OID ecPublicKey (1.2.840.10045.2.1), OID prime256v1 (1.2.840.10045.3.1.7) }
            $algoId = hex2bin('301306072a8648ce3d020106082a8648ce3d030107');
            if ($algoId === false) {
                return null;
            }

            $spki = "\x30" . self::encodeAsn1Length(strlen($algoId) + 1 + strlen($pointDer))
                . $algoId . "\x03" . self::encodeAsn1Length(1 + strlen($pointDer)) . "\x00" . $pointDer;

            $pem = "-----BEGIN PUBLIC KEY-----\n"
                . chunk_split(base64_encode($spki), 64, "\n")
                . '-----END PUBLIC KEY-----';

            $result = openssl_verify($messageDigest, $signatureRaw, $pem, OPENSSL_ALGO_SHA256);

            return $result === 1;
        } catch (\Throwable $e) {
            Log::warning('App Attest: OpenSSL verify exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Encode an integer as ASN.1 length bytes.
     */
    private static function encodeAsn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
