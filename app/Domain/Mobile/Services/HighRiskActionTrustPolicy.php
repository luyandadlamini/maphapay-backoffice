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
    ) {}

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
                $attestationVerified = $this->biometricJwtService->verifyDeviceAttestation($attestation, $deviceType);

                if (! $attestationVerified) {
                    $decision = 'deny';
                    $reason = 'attestation_failed';
                } else {
                    $decision = 'allow';
                    $reason = 'attestation_verified';
                }
            }
        } elseif ($attestation === '' && ! $deviceTrusted) {
            $decision = 'degrade';
            $reason = $devicePostureStatus === 'simulator_or_emulator'
                ? 'device_posture_untrusted'
                : 'attestation_disabled_device_untrusted';
        }

        $record = MobileAttestationRecord::query()->create([
            'user_id' => $user->id,
            'mobile_device_id' => $mobileDevice?->id,
            'action' => $action,
            'decision' => $decision,
            'reason' => $reason,
            'attestation_enabled' => $attestationEnabled,
            'attestation_verified' => $attestationVerified,
            'device_type' => $deviceType !== '' ? $deviceType : null,
            'device_id' => $deviceId !== '' ? $deviceId : null,
            'payload_hash' => $attestation !== '' ? hash('sha256', $attestation) : null,
            'request_path' => $request->path(),
            'metadata' => [
                'device_trusted' => $deviceTrusted,
                'device_posture' => [
                    'source' => $devicePostureSource !== '' ? $devicePostureSource : null,
                    'status' => $devicePostureStatus !== '' ? $devicePostureStatus : null,
                    'reason' => $devicePostureReason !== '' ? $devicePostureReason : null,
                ],
                'attestation_present' => $attestation !== '',
                'attestation_status' => $attestationStatus !== '' ? $attestationStatus : null,
                'attestation_capability' => [
                    'available' => $attestationCapabilityAvailable,
                    'mode' => $attestationCapabilityMode !== '' ? $attestationCapabilityMode : null,
                    'reason' => $attestationCapabilityReason !== '' ? $attestationCapabilityReason : null,
                ],
            ],
        ]);

        return [
            'decision' => $decision,
            'reason' => $reason,
            'attestation_verified' => $attestationVerified,
            'record_id' => (string) $record->id,
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
}
