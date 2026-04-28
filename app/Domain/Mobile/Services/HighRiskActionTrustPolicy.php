<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Services;

use App\Domain\Mobile\Contracts\BiometricJWTServiceInterface;
use App\Domain\Mobile\Models\MobileAttestationRecord;
use App\Domain\Mobile\Models\MobileDevice;
use App\Models\User;
use Illuminate\Http\Request;

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

        $decision = 'allow';
        $reason = 'attestation_disabled';
        $attestationVerified = false;

        if ($attestationEnabled) {
            if ($attestation === '') {
                $decision = 'deny';
                $reason = $attestationStatus === 'error' && $attestationCapabilityReason === 'provider_error'
                    ? 'attestation_provider_error'
                    : 'attestation_required';
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
            ],
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
}
