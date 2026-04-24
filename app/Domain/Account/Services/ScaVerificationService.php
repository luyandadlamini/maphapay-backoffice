<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Mobile\Services\BiometricAuthenticationService;
use App\Domain\Mobile\Models\MobileDevice;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ScaVerificationService
{
    public function __construct(
        private readonly BiometricAuthenticationService $biometricService
    ) {}

    public function verifyOtp(string $userUuid, string $otpCode): bool
    {
        Log::info('SCA verification: verifying OTP', [
            'user_uuid' => $userUuid,
        ]);

        $user = \App\Models\User::where('uuid', $userUuid)->first();
        if (! $user) {
            throw new RuntimeException('User not found');
        }

        $userId = $user->id;

        $latestPending = \App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction::where('user_id', $userId)
            ->where('status', 'pending')
            ->where('verification_type', 'otp')
            ->whereNotNull('otp_hash')
            ->where('otp_expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();

        if (! $latestPending || ! $latestPending->otp_hash) {
            Log::warning('SCA verification: no pending OTP transaction found', [
                'user_uuid' => $userUuid,
            ]);

            return false;
        }

        $result = \Illuminate\Support\Facades\Hash::check($otpCode, $latestPending->otp_hash);

        Log::info('SCA verification: OTP result', [
            'user_uuid' => $userUuid,
            'valid' => $result,
        ]);

        return $result;
    }

    public function verifyBiometric(string $userUuid, string $deviceId, string $biometricToken): bool
    {
        Log::info('SCA verification: verifying biometric', [
            'user_uuid' => $userUuid,
            'device_id' => $deviceId,
        ]);

        $user = \App\Models\User::where('uuid', $userUuid)->first();
        if (! $user) {
            throw new RuntimeException('User not found');
        }

        $device = MobileDevice::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->first();

        if (! $device) {
            Log::warning('SCA verification: device not found', [
                'user_uuid' => $userUuid,
                'device_id' => $deviceId,
            ]);

            return false;
        }

        if (! $device->biometric_enabled || ! $device->biometric_public_key) {
            Log::warning('SCA verification: biometric not enabled on device', [
                'user_uuid' => $userUuid,
                'device_id' => $deviceId,
            ]);

            return false;
        }

        $parts = explode('.', $biometricToken);
        if (count($parts) !== 3) {
            Log::warning('SCA verification: invalid biometric token format', [
                'user_uuid' => $userUuid,
            ]);

            return false;
        }

        $challenge = $parts[0] . '.' . $parts[1];
        $signature = $parts[2];

        try {
            $ipAddress = request()->ip();
            $result = $this->biometricService->verifyTransactionChallengeSignature(
                $device,
                $challenge,
                $signature,
                null,
                $ipAddress
            );

            Log::info('SCA verification: biometric result', [
                'user_uuid' => $userUuid,
                'device_id' => $deviceId,
                'valid' => $result,
            ]);

            return $result;
        } catch (\App\Domain\Mobile\Exceptions\BiometricBlockedException $e) {
            Log::warning('SCA verification: biometric blocked', [
                'user_uuid' => $userUuid,
                'device_id' => $deviceId,
            ]);

            return false;
        }
    }
}