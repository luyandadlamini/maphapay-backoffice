<?php

declare(strict_types=1);

use App\Domain\Mobile\Exceptions\BiometricBlockedException;
use App\Domain\Mobile\Models\BiometricChallenge;
use App\Domain\Mobile\Models\MobileDevice;
use App\Domain\Mobile\Services\PasskeyAuthenticationService;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

uses(Tests\TestCase::class);

/**
 * Generate an ECDSA P-256 key pair for passkey sign/verify tests.
 *
 * @return array{private_key: OpenSSLAsymmetricKey, public_key_pem: string}
 */
function generatePasskeyKeyPair(): array
{
    $key = @openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ]);

    if ($key === false) {
        $key = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
    }

    assert($key !== false, 'OpenSSL EC key generation failed');

    $details = openssl_pkey_get_details($key);
    assert($details !== false);

    return [
        'private_key'    => $key,
        'public_key_pem' => $details['key'],
    ];
}

/**
 * Build valid WebAuthn assertion data for testing.
 *
 * @return array{authenticator_data: string, client_data_json: string, signature: string}
 */
function buildWebAuthnAssertion(string $challenge, OpenSSLAsymmetricKey $privateKey): array
{
    // Minimal authenticator data (37 bytes: 32-byte rpIdHash + 1 flags + 4 counter)
    $rpIdHash = hash('sha256', 'https://localhost', true);
    $flags = chr(0x05); // UP + UV flags set
    $counter = pack('N', 1);
    $authenticatorData = $rpIdHash . $flags . $counter;

    // Client data JSON per WebAuthn spec
    $clientData = json_encode([
        'type'      => 'webauthn.get',
        'challenge' => $challenge,
        'origin'    => 'https://localhost',
    ], JSON_THROW_ON_ERROR);

    // WebAuthn signature = sign(authenticatorData || SHA256(clientDataJSON))
    $clientDataHash = hash('sha256', $clientData, true);
    $signedData = $authenticatorData . $clientDataHash;

    openssl_sign($signedData, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    return [
        'authenticator_data' => base64_encode($authenticatorData),
        'client_data_json'   => base64_encode($clientData),
        'signature'          => base64_encode($signature),
    ];
}

beforeEach(function (): void {
    Cache::flush();

    $this->service = new PasskeyAuthenticationService();
    $this->user = User::factory()->create();
    $this->keyPair = generatePasskeyKeyPair();
    $this->device = MobileDevice::factory()->create([
        'user_id'               => $this->user->id,
        'passkey_enabled'       => true,
        'passkey_credential_id' => 'test-credential-id-abc123',
        'passkey_public_key'    => $this->keyPair['public_key_pem'],
        'passkey_enabled_at'    => now(),
    ]);
});

describe('PasskeyAuthenticationService', function (): void {
    describe('generateChallenge', function (): void {
        it('creates a challenge for a device', function (): void {
            $challenge = $this->service->generateChallenge($this->device);

            expect($challenge)->toBeInstanceOf(BiometricChallenge::class);
            expect($challenge->mobile_device_id)->toBe($this->device->id);
            expect($challenge->status)->toBe(BiometricChallenge::STATUS_PENDING);
            expect($challenge->challenge)->not->toBeEmpty();
        });

        it('invalidates previous pending challenges', function (): void {
            $first = $this->service->generateChallenge($this->device);
            $second = $this->service->generateChallenge($this->device);

            $first->refresh();

            expect($first->status)->toBe(BiometricChallenge::STATUS_EXPIRED);
            expect($second->status)->toBe(BiometricChallenge::STATUS_PENDING);
        });
    });

    describe('verifyAndAuthenticate', function (): void {
        it('authenticates successfully with valid WebAuthn assertion', function (): void {
            $challenge = $this->service->generateChallenge($this->device);
            $assertion = buildWebAuthnAssertion($challenge->challenge, $this->keyPair['private_key']);

            $result = $this->service->verifyAndAuthenticate(
                device: $this->device,
                challenge: $challenge->challenge,
                credentialId: 'test-credential-id-abc123',
                authenticatorData: $assertion['authenticator_data'],
                clientDataJSON: $assertion['client_data_json'],
                signature: $assertion['signature'],
            );

            expect($result)->not->toBeNull();
            expect($result['access_token'])->toBeString()->not->toBeEmpty();
            expect($result['refresh_token'])->toBeString()->not->toBeEmpty();
            expect($result['expires_at'])->toBeInstanceOf(Carbon\Carbon::class);
            expect($result['session_id'])->not->toBeEmpty();
        });

        it('returns null when device is blocked', function (): void {
            $this->device->update(['is_blocked' => true, 'blocked_reason' => 'Security']);

            $result = $this->service->verifyAndAuthenticate(
                device: $this->device,
                challenge: 'test',
                credentialId: 'test-credential-id-abc123',
                authenticatorData: base64_encode('data'),
                clientDataJSON: base64_encode('{}'),
                signature: base64_encode('sig'),
            );

            expect($result)->toBeNull();
        });

        it('returns null when passkey is not enabled', function (): void {
            $this->device->update(['passkey_enabled' => false]);

            $result = $this->service->verifyAndAuthenticate(
                device: $this->device,
                challenge: 'test',
                credentialId: 'test-credential-id-abc123',
                authenticatorData: base64_encode('data'),
                clientDataJSON: base64_encode('{}'),
                signature: base64_encode('sig'),
            );

            expect($result)->toBeNull();
        });

        it('returns null when credential ID does not match', function (): void {
            $challenge = $this->service->generateChallenge($this->device);

            $result = $this->service->verifyAndAuthenticate(
                device: $this->device,
                challenge: $challenge->challenge,
                credentialId: 'wrong-credential-id',
                authenticatorData: base64_encode('data'),
                clientDataJSON: base64_encode('{}'),
                signature: base64_encode('sig'),
            );

            expect($result)->toBeNull();

            $this->assertDatabaseHas('biometric_failures', [
                'mobile_device_id' => $this->device->id,
                'failure_reason'   => 'passkey_credential_mismatch',
            ]);
        });

        it('returns null when challenge is not found', function (): void {
            $result = $this->service->verifyAndAuthenticate(
                device: $this->device,
                challenge: 'nonexistent-challenge',
                credentialId: 'test-credential-id-abc123',
                authenticatorData: base64_encode('data'),
                clientDataJSON: base64_encode('{}'),
                signature: base64_encode('sig'),
            );

            expect($result)->toBeNull();

            $this->assertDatabaseHas('biometric_failures', [
                'mobile_device_id' => $this->device->id,
                'failure_reason'   => 'passkey_challenge_not_found',
            ]);
        });

        it('returns null when signature is invalid', function (): void {
            $challenge = $this->service->generateChallenge($this->device);

            // Build valid client data but with a wrong signature
            $clientData = json_encode([
                'type'      => 'webauthn.get',
                'challenge' => $challenge->challenge,
                'origin'    => 'https://localhost',
            ], JSON_THROW_ON_ERROR);

            $result = $this->service->verifyAndAuthenticate(
                device: $this->device,
                challenge: $challenge->challenge,
                credentialId: 'test-credential-id-abc123',
                authenticatorData: base64_encode(str_repeat("\x00", 37)),
                clientDataJSON: base64_encode($clientData),
                signature: base64_encode('invalid-signature'),
            );

            expect($result)->toBeNull();

            $this->assertDatabaseHas('biometric_failures', [
                'mobile_device_id' => $this->device->id,
                'failure_reason'   => 'passkey_signature_invalid',
            ]);
        });

        it('throws BiometricBlockedException after too many failures', function (): void {
            // Set max failures to 1 for testing
            config(['mobile.security.max_biometric_failures' => 1]);

            // Create a failure record to trigger blocking
            App\Domain\Mobile\Models\BiometricFailure::create([
                'mobile_device_id' => $this->device->id,
                'failure_reason'   => 'passkey_test_failure',
                'ip_address'       => '127.0.0.1',
            ]);

            expect(fn () => $this->service->verifyAndAuthenticate(
                device: $this->device,
                challenge: 'test',
                credentialId: 'test-credential-id-abc123',
                authenticatorData: base64_encode('data'),
                clientDataJSON: base64_encode('{}'),
                signature: base64_encode('sig'),
            ))->toThrow(BiometricBlockedException::class);
        });
    });

    describe('registerPasskey', function (): void {
        it('registers a passkey credential on a device', function (): void {
            $device = MobileDevice::factory()->create([
                'user_id'         => $this->user->id,
                'passkey_enabled' => false,
            ]);

            $result = $this->service->registerPasskey(
                $device,
                'new-credential-id',
                $this->keyPair['public_key_pem'],
            );

            expect($result['credential_id'])->toBe('new-credential-id');
            expect($result['registered_at'])->toBeString();

            $device->refresh();
            expect($device->passkey_enabled)->toBeTrue();
            expect($device->passkey_credential_id)->toBe('new-credential-id');
            expect($device->passkey_public_key)->toBe($this->keyPair['public_key_pem']);
        });
    });
});
