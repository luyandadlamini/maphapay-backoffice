<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Services;

use App\Domain\Mobile\Contracts\AppAttestVerifierInterface;
use App\Domain\Mobile\DataObjects\AppAttestIssuedChallenge;
use App\Domain\Mobile\DataObjects\AppAttestVerificationResult;
use App\Domain\Mobile\Exceptions\AppAttestException;
use App\Domain\Mobile\Models\MobileAppAttestChallenge;
use App\Domain\Mobile\Models\MobileAppAttestKey;
use App\Domain\Mobile\Models\MobileDevice;
use Illuminate\Support\Facades\DB;

class AppAttestService
{
    public function __construct(
        private readonly AppAttestVerifierInterface $verifier,
    ) {
    }

    public function issueChallenge(MobileDevice $device, string $purpose, ?string $keyId = null): AppAttestIssuedChallenge
    {
        $plainChallenge = bin2hex(random_bytes(32));

        $record = MobileAppAttestChallenge::query()->create([
            'mobile_device_id' => $device->id,
            'user_id'          => $device->user_id,
            'purpose'          => $purpose,
            'key_id'           => $keyId,
            'challenge_hash'   => hash('sha256', $plainChallenge),
            'expires_at'       => now()->addSeconds((int) config('mobile.attestation.apple.challenge_ttl_seconds', 300)),
        ]);

        return new AppAttestIssuedChallenge(
            $record->id,
            $plainChallenge,
            $record->purpose,
            $record->expires_at,
        );
    }

    public function enrollKey(
        MobileDevice $device,
        string $challengeId,
        string $challenge,
        string $keyId,
        string $attestationObject,
    ): MobileAppAttestKey {
        $challengeRecord = $this->resolveChallenge(
            $device,
            $challengeId,
            $challenge,
            MobileAppAttestChallenge::PURPOSE_ENROLLMENT,
            null,
        );

        $verification = $this->verifier->verifyAttestation($attestationObject, $challenge, $keyId);

        if (! $verification->verified) {
            throw AppAttestException::enrollmentFailed($verification->reason);
        }

        return DB::transaction(function () use ($device, $challengeRecord, $keyId, $verification): MobileAppAttestKey {
            $key = MobileAppAttestKey::query()->updateOrCreate(
                [
                    'mobile_device_id' => $device->id,
                    'user_id'          => $device->user_id,
                    'key_id'           => $keyId,
                ],
                [
                    'status'      => MobileAppAttestKey::STATUS_ACTIVE,
                    'attested_at' => now(),
                    'metadata'    => $verification->metadata,
                ],
            );

            $challengeRecord->update([
                'mobile_app_attest_key_id' => $key->id,
                'consumed_at'              => now(),
            ]);

            $freshKey = $key->fresh();

            if (! $freshKey instanceof MobileAppAttestKey) {
                return $key;
            }

            return $freshKey;
        });
    }

    public function validateAssertionPrerequisites(
        MobileDevice $device,
        string $challengeId,
        string $challenge,
        string $keyId,
    ): AppAttestVerificationResult {
        $key = MobileAppAttestKey::query()
            ->where('mobile_device_id', $device->id)
            ->where('user_id', $device->user_id)
            ->where('key_id', $keyId)
            ->where('status', MobileAppAttestKey::STATUS_ACTIVE)
            ->first();

        if (! $key) {
            return AppAttestVerificationResult::failure('app_attest_key_not_found');
        }

        try {
            $this->resolveChallenge(
                $device,
                $challengeId,
                $challenge,
                MobileAppAttestChallenge::PURPOSE_ASSERTION,
                $keyId,
            );
        } catch (AppAttestException $e) {
            return AppAttestVerificationResult::failure($e->getMessage());
        }

        return AppAttestVerificationResult::success([], 'assertion_prerequisites_verified');
    }

    private function resolveChallenge(
        MobileDevice $device,
        string $challengeId,
        string $challenge,
        string $purpose,
        ?string $keyId,
    ): MobileAppAttestChallenge {
        $record = MobileAppAttestChallenge::query()
            ->where('id', $challengeId)
            ->where('mobile_device_id', $device->id)
            ->where('user_id', $device->user_id)
            ->where('purpose', $purpose)
            ->first();

        if (! $record) {
            throw AppAttestException::invalidChallenge('challenge_not_found');
        }

        if ($keyId !== null && $record->key_id !== $keyId) {
            throw AppAttestException::invalidChallenge('challenge_key_mismatch');
        }

        if (! hash_equals($record->challenge_hash, hash('sha256', $challenge))) {
            throw AppAttestException::invalidChallenge('challenge_mismatch');
        }

        if (! $record->isUsable()) {
            throw AppAttestException::invalidChallenge('challenge_expired_or_consumed');
        }

        return $record;
    }
}
