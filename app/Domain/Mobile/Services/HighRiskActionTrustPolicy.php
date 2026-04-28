<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Services;

use App\Domain\Mobile\Contracts\BiometricJWTServiceInterface;
use App\Domain\Mobile\Models\MobileAttestationRecord;
use App\Domain\Mobile\Models\MobileDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HighRiskActionTrustPolicy
{
    public function __construct(
        private readonly BiometricJWTServiceInterface $biometricJwtService,
    ) {
    }

    /**
     * @return array{decision: string, reason: string, attestation_verified: bool, record_id: string}
     */
    public function evaluate(User $user, Request $request, string $action): array
    {
        $attestation = trim((string) $request->input('attestation', ''));
        $deviceType = strtolower(trim((string) ($request->input('device_type') ?? $request->header('X-Mobile-Platform', ''))));
        $deviceId = trim((string) ($request->input('device_id') ?? $request->header('X-Device-ID', '')));
        $devicePostureSource = strtolower(trim((string) $request->input('device_posture_source', '')));
        $devicePostureStatus = strtolower(trim((string) $request->input('device_posture_status', '')));
        $devicePostureReason = trim((string) $request->input('device_posture_reason', ''));
        $attestationStatus = strtolower(trim((string) $request->input('attestation_status', '')));
        $attestationCapabilityMode = strtolower(trim((string) $request->input('attestation_capability_mode', '')));
        $attestationCapabilityReason = trim((string) $request->input('attestation_capability_reason', ''));
        $attestationCapabilityAvailable = $request->has('attestation_capability_available')
            ? filter_var($request->input('attestation_capability_available'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            : null;
        $attestationEnabled = (bool) config('mobile.attestation.enabled', false);
        $attestationCountsAsTrustProof = $this->attestationCountsAsTrustProof($attestation, $attestationCapabilityMode);

        $mobileDevice = $this->resolveMobileDevice($user, $deviceId);
        $deviceTrusted = $mobileDevice?->is_trusted === true;
        $attestationPayloadMetadata = $this->safeAttestationPayloadMetadata($attestation, $deviceType);

        $decision = 'allow';
        $reason = 'attestation_disabled';
        $attestationVerified = false;

        if ($attestationEnabled) {
            if ($attestation === '') {
                $collectionFailureReason = $this->attestationCollectionFailureReason(
                    $attestationStatus,
                    $attestationCapabilityReason,
                );

                $decision = 'deny';
                $reason = $collectionFailureReason ?? 'attestation_required';
            } elseif (! in_array($deviceType, ['ios', 'android'], true)) {
                $decision = 'deny';
                $reason = 'unsupported_device_type';
            } else {
                $attestationVerified = $this->verifyAttestationPayload($attestation, $deviceType, $deviceId);

                if (! $attestationVerified) {
                    $decision = 'deny';
                    $reason = 'attestation_failed';
                } else {
                    $decision = 'allow';
                    $reason = 'attestation_verified';
                }
            }
        } elseif (! $attestationCountsAsTrustProof && ! $deviceTrusted) {
            $decision = 'degrade';
            $reason = $devicePostureStatus === 'simulator_or_emulator'
                ? 'device_posture_untrusted'
                : 'attestation_disabled_device_untrusted';
        }

        $record = MobileAttestationRecord::query()->create([
            'user_id'              => $user->id,
            'mobile_device_id'     => $mobileDevice?->id,
            'action'               => $action,
            'decision'             => $decision,
            'reason'               => $reason,
            'attestation_enabled'  => $attestationEnabled,
            'attestation_verified' => $attestationVerified,
            'device_type'          => $deviceType !== '' ? $deviceType : null,
            'device_id'            => $deviceId !== '' ? $deviceId : null,
            'payload_hash'         => $attestation !== '' ? hash('sha256', $attestation) : null,
            'request_path'         => $request->path(),
            'metadata'             => [
                'device_trusted' => $deviceTrusted,
                'device_posture' => [
                    'source' => $devicePostureSource !== '' ? $devicePostureSource : null,
                    'status' => $devicePostureStatus !== '' ? $devicePostureStatus : null,
                    'reason' => $devicePostureReason !== '' ? $devicePostureReason : null,
                ],
                'attestation_present'    => $attestation !== '',
                'attestation_status'     => $attestationStatus !== '' ? $attestationStatus : null,
                'attestation_capability' => [
                    'available' => $attestationCapabilityAvailable,
                    'mode'      => $attestationCapabilityMode !== '' ? $attestationCapabilityMode : null,
                    'reason'    => $attestationCapabilityReason !== '' ? $attestationCapabilityReason : null,
                ],
                'device_lookup' => [
                    'found'   => $mobileDevice !== null,
                    'trusted' => $deviceTrusted,
                ],
                'app_attest' => $attestationPayloadMetadata,
            ],
        ]);

        Log::info('mobile.high_risk_trust_policy.evaluated', [
            'user_id'                       => $user->id,
            'action'                        => $action,
            'decision'                      => $decision,
            'reason'                        => $reason,
            'record_id'                     => (string) $record->id,
            'device_type'                   => $deviceType !== '' ? $deviceType : null,
            'device_id_present'             => $deviceId !== '',
            'mobile_device_found'           => $mobileDevice !== null,
            'attestation_enabled'           => $attestationEnabled,
            'attestation_present'           => $attestation !== '',
            'attestation_verified'          => $attestationVerified,
            'attestation_status'            => $attestationStatus !== '' ? $attestationStatus : null,
            'attestation_capability_mode'   => $attestationCapabilityMode !== '' ? $attestationCapabilityMode : null,
            'attestation_capability_reason' => $attestationCapabilityReason !== '' ? $attestationCapabilityReason : null,
            'app_attest'                    => $attestationPayloadMetadata,
        ]);

        return [
            'decision'             => $decision,
            'reason'               => $reason,
            'attestation_verified' => $attestationVerified,
            'record_id'            => (string) $record->id,
        ];
    }

    private function resolveMobileDevice(User $user, string $deviceId): ?MobileDevice
    {
        if ($deviceId === '') {
            return null;
        }

        return MobileDevice::query()
            ->where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->first();
    }

    private function attestationCountsAsTrustProof(string $attestation, string $attestationCapabilityMode): bool
    {
        if ($attestation === '') {
            return false;
        }

        return ! in_array($attestationCapabilityMode, ['none', 'runtime-posture'], true);
    }

    private function attestationCollectionFailureReason(string $attestationStatus, string $capabilityReason): ?string
    {
        if ($attestationStatus !== 'error' || $capabilityReason === '') {
            return null;
        }

        return match ($capabilityReason) {
            'provider_error' => 'attestation_provider_error',
            'device_registration_failed',
            'challenge_failed',
            'key_generation_failed',
            'enrollment_failed',
            'assertion_generation_failed',
            'assertion_verify_http_failed',
            'envelope_failed',
            'native_module_missing',
            'native_module_unavailable' => 'attestation_' . $capabilityReason,
            default => 'attestation_provider_error',
        };
    }

    /**
     * Verify attestation material sent with high-risk API calls.
     *
     * iOS (Expo/RN) sends a short JSON envelope prefixed with `ios-app-attest:` after the app has
     * already completed cryptographic App Attest flows against `/api/mobile/auth/attestation/app-attest/*`.
     * That envelope must not be passed to AppleAttestationVerifier (expects base64 CBOR + challenge).
     *
     * @see https://developer.apple.com/documentation/devicecheck/validating_apps_that_connect_to_your_server
     */
    private function verifyAttestationPayload(string $attestation, string $deviceType, string $deviceId): bool
    {
        if ($deviceType === 'ios' && str_starts_with($attestation, 'ios-app-attest:')) {
            return $this->verifyIosAppAttestClientEnvelope($attestation, $deviceId);
        }

        return $this->biometricJwtService->verifyDeviceAttestation($attestation, $deviceType);
    }

    /**
     * Validates the RN client's post-verify envelope: JSON after `ios-app-attest:` with device binding.
     * Cryptographic assertion verification happens in AppAttestService::verifyAssertion; this
     * layer only accepts well-formed proofs tied to the same device_id as the money request.
     *
     * @param  string  $deviceId  Resolved client device id (body or X-Device-ID)
     */
    private function verifyIosAppAttestClientEnvelope(string $attestation, string $deviceId): bool
    {
        if ($deviceId === '') {
            return false;
        }

        $prefix = 'ios-app-attest:';
        $json = substr($attestation, strlen($prefix));
        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            return false;
        }

        $envelopeDeviceId = isset($payload['deviceId']) && is_string($payload['deviceId'])
            ? trim($payload['deviceId'])
            : '';

        if ($envelopeDeviceId === '' || ! hash_equals($envelopeDeviceId, $deviceId)) {
            return false;
        }

        $assertionReason = isset($payload['assertionReason']) && is_string($payload['assertionReason'])
            ? strtolower(trim($payload['assertionReason']))
            : '';

        $allowedReasons = [
            'assertion_verified',
            'verified',
            'attestation_verified',
            'assertion_prerequisites_verified',
        ];

        return in_array($assertionReason, $allowedReasons, true);
    }

    /**
     * @return array<string, bool|string|null>|null
     */
    private function safeAttestationPayloadMetadata(string $attestation, string $deviceType): ?array
    {
        if ($attestation === '') {
            return null;
        }

        if ($deviceType !== 'ios' || ! str_starts_with($attestation, 'ios-app-attest:')) {
            return [
                'prefix' => str_contains($attestation, ':')
                    ? substr($attestation, 0, (int) strpos($attestation, ':'))
                    : 'unprefixed',
            ];
        }

        $payload = json_decode(substr($attestation, strlen('ios-app-attest:')), true);

        if (! is_array($payload)) {
            return [
                'prefix'      => 'ios-app-attest',
                'parse_error' => 'invalid_json',
            ];
        }

        $metadata = isset($payload['metadata']) && is_array($payload['metadata'])
            ? $payload['metadata']
            : [];

        return [
            'prefix'               => 'ios-app-attest',
            'key_id_present'       => isset($payload['keyId']) && is_string($payload['keyId']) && trim($payload['keyId']) !== '',
            'assertion_reason'     => isset($payload['assertionReason']) && is_string($payload['assertionReason'])
                ? strtolower(trim($payload['assertionReason']))
                : null,
            'challenge_id_present' => isset($metadata['challenge_id']) && is_string($metadata['challenge_id']) && trim($metadata['challenge_id']) !== '',
        ];
    }
}
