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
        $attestationEnabled = (bool) config('mobile.attestation.enabled', false);

        $mobileDevice = $this->resolveMobileDevice($user, $deviceId);
        $deviceTrusted = $mobileDevice?->is_trusted === true;

        $decision = 'allow';
        $reason = 'attestation_disabled';
        $attestationVerified = false;

        if ($attestationEnabled) {
            if ($attestation === '') {
                $decision = 'deny';
                $reason = 'attestation_required';
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
            $reason = 'attestation_disabled_device_untrusted';
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
                'attestation_present' => $attestation !== '',
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
