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
            Log::warning('App Attest: COSE key format mismatch', [
                'cose_kty' => $cose[self::COSE_KEY_KTY] ?? null,
                'cose_crv' => $cose[self::COSE_EC2_CRV] ?? null,
                'cose_has_x' => isset($cose[self::COSE_EC2_X]),
                'cose_has_y' => isset($cose[self::COSE_EC2_Y]),
                'cose_x_length' => isset($cose[self::COSE_EC2_X]) && is_string($cose[self::COSE_EC2_X]) ? strlen($cose[self::COSE_EC2_X]) : null,
                'cose_y_length' => isset($cose[self::COSE_EC2_Y]) && is_string($cose[self::COSE_EC2_Y]) ? strlen($cose[self::COSE_EC2_Y]) : null,
                'cose_keys' => array_keys($cose),
            ]);
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
        $messageDigestHex = bin2hex(hash('sha256', $authenticatorData . $clientDataHash, true));

        if (strlen($signature) !== 64) {
            return ['verified' => false, 'reason' => 'assertion_signature_length_invalid'];
        }

        $rHex = bin2hex(substr($signature, 0, 32));
        $sHex = bin2hex(substr($signature, 32, 32));

        $pkHex = strtolower(preg_replace('/^0x/', '', $credentialPublicKeyHex) ?? '');

        if (strlen($pkHex) !== 130 || ! str_starts_with($pkHex, '04')) {
            return ['verified' => false, 'reason' => 'credential_public_key_hex_invalid'];
        }

        try {
            $ec = new EC('p256');
            $key = $ec->keyFromPublic($pkHex, 'hex');
            $ok = $ec->verify($messageDigestHex, new Signature([
                'r' => $rHex,
                's' => $sHex,
            ]), $key, 'hex');
        } catch (\Throwable $e) {
            Log::warning('App Attest: assertion verify exception', ['message' => $e->getMessage()]);

            return ['verified' => false, 'reason' => 'assertion_signature_verify_exception'];
        }

        if (! $ok) {
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

        if ($kty !== self::COSE_KTY_EC2 || $crv !== self::COSE_CRV_P256) {
            return null;
        }

        if (! is_string($x) || ! is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            return null;
        }

        return '04' . bin2hex($x) . bin2hex($y);
    }
}
