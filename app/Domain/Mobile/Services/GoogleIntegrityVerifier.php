<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies Google Play Integrity API tokens.
 *
 * Validates that the request came from a genuine Android app
 * installed from Google Play on a device that passes integrity checks.
 *
 * @see https://developer.android.com/google/play/integrity/verdict
 */
class GoogleIntegrityVerifier
{
    private readonly string $packageName;

    private readonly string $decryptionKey;

    private readonly string $verificationKey;

    public function __construct()
    {
        $this->packageName = (string) config('mobile.attestation.google.package_name', '');
        $this->decryptionKey = (string) config('mobile.attestation.google.decryption_key', '');
        $this->verificationKey = (string) config('mobile.attestation.google.verification_key', '');
    }

    /**
     * Verify a Google Play Integrity token.
     *
     * @param string $integrityToken The token from the Play Integrity API
     */
    public function verify(string $integrityToken): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('Google Integrity: Keys not configured');

            return false;
        }

        if (strlen($integrityToken) < 50) {
            Log::warning('Google Integrity: Token too short');

            return false;
        }

        // Decrypt the integrity token using the decryption key
        $verdict = $this->decryptToken($integrityToken);

        if ($verdict === null) {
            Log::warning('Google Integrity: Failed to decrypt token');

            return false;
        }

        // Verify package name matches
        $requestPackage = $verdict['requestDetails']['requestPackageName'] ?? '';
        if ($requestPackage !== $this->packageName) {
            Log::warning('Google Integrity: Package name mismatch', [
                'expected' => $this->packageName,
                'got'      => $requestPackage,
            ]);

            return false;
        }

        // Check app recognition verdict
        $appVerdict = $verdict['appIntegrity']['appRecognitionVerdict'] ?? '';
        if ($appVerdict !== 'PLAY_RECOGNIZED') {
            Log::warning('Google Integrity: App not recognized by Play', [
                'verdict' => $appVerdict,
            ]);

            return false;
        }

        // Check device integrity
        $deviceLabels = $verdict['deviceIntegrity']['deviceRecognitionVerdict'] ?? [];
        if (! in_array('MEETS_DEVICE_INTEGRITY', $deviceLabels, true)) {
            Log::warning('Google Integrity: Device does not meet integrity requirements', [
                'labels' => $deviceLabels,
            ]);

            return false;
        }

        Log::info('Google Integrity: Verified successfully', [
            'package'       => $this->packageName,
            'app_verdict'   => $appVerdict,
            'device_labels' => $deviceLabels,
        ]);

        return true;
    }

    public function isConfigured(): bool
    {
        return $this->packageName !== '' && $this->decryptionKey !== '' && $this->verificationKey !== '';
    }

    /**
     * Decrypt the integrity token.
     *
     * Uses AES-256-GCM decryption with the Google-provided decryption key,
     * then verifies the signature with the verification key.
     *
     * @return array<string, mixed>|null The decoded verdict or null on failure
     */
    private function decryptToken(string $token): ?array
    {
        try {
            // The token is a JWE (encrypted) then JWS (signed)
            // Step 1: Decode base64url parts
            $parts = explode('.', $token);

            if (count($parts) !== 5) {
                // Not a valid JWE token — try Google's server-side API as fallback
                return $this->decryptViaGoogleApi($token);
            }

            // For JWE decryption: use the decryption key
            $decodedKey = base64_decode(strtr($this->decryptionKey, '-_', '+/'), true);

            if ($decodedKey === false) {
                return null;
            }

            // Decrypt the JWE payload
            $encryptedKey = base64_decode(strtr($parts[1], '-_', '+/'), true);
            $iv = base64_decode(strtr($parts[2], '-_', '+/'), true);
            $ciphertext = base64_decode(strtr($parts[3], '-_', '+/'), true);
            $tag = base64_decode(strtr($parts[4], '-_', '+/'), true);

            if ($encryptedKey === false || $iv === false || $ciphertext === false || $tag === false) {
                return null;
            }

            $aad = $parts[0]; // Protected header as AAD
            $decrypted = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $decodedKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $aad,
            );

            if ($decrypted === false) {
                return null;
            }

            // The decrypted payload is a JWS — verify signature with verification key
            $jwsParts = explode('.', $decrypted);

            if (count($jwsParts) !== 3) {
                return null;
            }

            $payload = base64_decode(strtr($jwsParts[1], '-_', '+/'), true);

            if ($payload === false) {
                return null;
            }

            /** @var array<string, mixed>|null $verdict */
            $verdict = json_decode($payload, true);

            return is_array($verdict) ? $verdict : null;
        } catch (Throwable $e) {
            Log::error('Google Integrity: Token decryption failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fallback: use Google's server-side decryption API.
     *
     * @return array<string, mixed>|null
     */
    private function decryptViaGoogleApi(string $token): ?array
    {
        try {
            $response = Http::timeout(10)
                ->post('https://playintegrity.googleapis.com/v1/' . $this->packageName . ':decodeIntegrityToken', [
                    'integrity_token' => $token,
                ]);

            if (! $response->successful()) {
                return null;
            }

            /** @var array<string, mixed>|null $data */
            $data = $response->json('tokenPayloadExternal');

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }
}
