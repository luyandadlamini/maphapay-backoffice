<?php

declare(strict_types=1);

use App\Domain\Mobile\Contracts\BiometricJWTServiceInterface;
use App\Domain\Mobile\Services\HighRiskActionTrustPolicy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\UnitTestCase;

uses(UnitTestCase::class);

beforeEach(function (): void {
    if (! Schema::hasTable('mobile_attestation_records')) {
        Schema::create('mobile_attestation_records', function ($table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->uuid('mobile_device_id')->nullable();
            $table->string('action', 120);
            $table->string('decision', 30);
            $table->string('reason', 120);
            $table->boolean('attestation_enabled')->default(false);
            $table->boolean('attestation_verified')->default(false);
            $table->string('device_type', 30)->nullable();
            $table->string('device_id', 150)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->string('request_path', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    DB::table('mobile_attestation_records')->delete();
});

describe('HighRiskActionTrustPolicy', function (): void {
    it('persists a degraded decision when attestation is disabled and absent', function (): void {
        Config::set('mobile.attestation.enabled', false);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 1001;

        $request = Request::create('/api/v1/commerce/payments', 'POST', [
            'payment_link_token' => 'good-token',
        ]);

        $result = $policy->evaluate($user, $request, 'commerce.payment.process');

        expect($result['decision'])->toBe('degrade')
            ->and($result['reason'])->toBe('attestation_disabled_device_untrusted')
            ->and($result['record_id'])->not->toBe('');

        $persisted = DB::table('mobile_attestation_records')->where('id', $result['record_id'])->first();
        expect($persisted)->not->toBeNull();
        assert($persisted !== null);
        expect($persisted)->not->toBeNull()
            ->and($persisted->decision)->toBe('degrade')
            ->and($persisted->action)->toBe('commerce.payment.process');
    });

    it('persists explicit device-posture metadata and a posture-specific degraded reason for simulator fallback', function (): void {
        Config::set('mobile.attestation.enabled', false);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 1004;

        $request = Request::create('/api/v1/commerce/payments', 'POST', [
            'payment_link_token'    => 'good-token',
            'device_type'           => 'ios',
            'device_posture_source' => 'runtime-observed',
            'device_posture_status' => 'simulator_or_emulator',
            'device_posture_reason' => 'non_physical_device',
        ]);

        $result = $policy->evaluate($user, $request, 'commerce.payment.process');

        expect($result['decision'])->toBe('degrade')
            ->and($result['reason'])->toBe('device_posture_untrusted');

        $persisted = DB::table('mobile_attestation_records')->where('id', $result['record_id'])->first();
        expect($persisted)->not->toBeNull();
        assert($persisted !== null);

        $metadata = json_decode((string) $persisted->metadata, true, 512, JSON_THROW_ON_ERROR);

        expect($metadata)
            ->toMatchArray([
                'device_posture' => [
                    'source' => 'runtime-observed',
                    'status' => 'simulator_or_emulator',
                    'reason' => 'non_physical_device',
                ],
            ]);
    });

    it('does not treat runtime-posture fallback payloads as real attestation proof when enforcement is disabled', function (): void {
        Config::set('mobile.attestation.enabled', false);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 1005;

        $request = Request::create('/api/v1/commerce/payments', 'POST', [
            'payment_link_token'               => 'good-token',
            'device_type'                      => 'ios',
            'attestation'                      => 'expo-runtime-attestation:{"mode":"runtime-posture"}',
            'attestation_status'               => 'collected',
            'attestation_capability_mode'      => 'runtime-posture',
            'attestation_capability_reason'    => 'ios_app_attest_native_collection_unimplemented',
            'attestation_capability_available' => false,
            'device_posture_source'            => 'runtime-observed',
            'device_posture_status'            => 'physical_device',
            'device_posture_reason'            => 'physical_device_confirmed',
        ]);

        $result = $policy->evaluate($user, $request, 'commerce.payment.process');

        expect($result['decision'])->toBe('degrade')
            ->and($result['reason'])->toBe('attestation_disabled_device_untrusted');

        $persisted = DB::table('mobile_attestation_records')->where('id', $result['record_id'])->first();
        expect($persisted)->not->toBeNull();
        assert($persisted !== null);

        $metadata = json_decode((string) $persisted->metadata, true, 512, JSON_THROW_ON_ERROR);

        expect($metadata)
            ->toMatchArray([
                'attestation_present'    => true,
                'attestation_status'     => 'collected',
                'attestation_capability' => [
                    'available' => false,
                    'mode'      => 'runtime-posture',
                    'reason'    => 'ios_app_attest_native_collection_unimplemented',
                ],
            ]);
    });

    it('still allows real attestation payloads to improve trust semantics when enforcement is disabled', function (): void {
        Config::set('mobile.attestation.enabled', false);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 1006;

        $request = Request::create('/api/v1/commerce/payments', 'POST', [
            'payment_link_token'               => 'good-token',
            'device_type'                      => 'ios',
            'attestation'                      => 'ios-app-attest:verified-payload',
            'attestation_status'               => 'collected',
            'attestation_capability_mode'      => 'app-attest',
            'attestation_capability_available' => true,
            'device_posture_source'            => 'runtime-observed',
            'device_posture_status'            => 'physical_device',
            'device_posture_reason'            => 'physical_device_confirmed',
        ]);

        $result = $policy->evaluate($user, $request, 'commerce.payment.process');

        expect($result['decision'])->toBe('allow')
            ->and($result['reason'])->toBe('attestation_disabled');
    });

    it('persists a deny decision when attestation is enabled but missing', function (): void {
        Config::set('mobile.attestation.enabled', true);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 1002;

        $request = Request::create('/api/v1/commerce/payments', 'POST', [
            'payment_link_token' => 'good-token',
            'device_type'        => 'ios',
        ]);

        $result = $policy->evaluate($user, $request, 'commerce.payment.process');

        expect($result['decision'])->toBe('deny')
            ->and($result['reason'])->toBe('attestation_required');

        $persisted = DB::table('mobile_attestation_records')->where('id', $result['record_id'])->first();
        expect($persisted)->not->toBeNull();
        assert($persisted !== null);
        expect($persisted)->not->toBeNull()
            ->and($persisted->attestation_enabled)->toBe(1)
            ->and($persisted->attestation_verified)->toBe(0)
            ->and($persisted->reason)->toBe('attestation_required');
    });

    it('persists explicit provider-error metadata when attestation collection fails under enforced policy', function (): void {
        Config::set('mobile.attestation.enabled', true);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 1003;

        $request = Request::create('/api/v1/commerce/payments', 'POST', [
            'payment_link_token'               => 'good-token',
            'device_type'                      => 'ios',
            'attestation_status'               => 'error',
            'attestation_capability_mode'      => 'none',
            'attestation_capability_reason'    => 'provider_error',
            'attestation_capability_available' => false,
        ]);

        $result = $policy->evaluate($user, $request, 'commerce.payment.process');

        expect($result['decision'])->toBe('deny')
            ->and($result['reason'])->toBe('attestation_provider_error');

        $persisted = DB::table('mobile_attestation_records')->where('id', $result['record_id'])->first();
        expect($persisted)->not->toBeNull();
        assert($persisted !== null);

        expect($persisted)->not->toBeNull()
            ->and($persisted->reason)->toBe('attestation_provider_error');

        $metadata = json_decode((string) $persisted->metadata, true, 512, JSON_THROW_ON_ERROR);

        expect($metadata)
            ->toMatchArray([
                'attestation_present'    => false,
                'attestation_status'     => 'error',
                'attestation_capability' => [
                    'available' => false,
                    'mode'      => 'none',
                    'reason'    => 'provider_error',
                ],
            ]);
    });

    it('preserves classified mobile attestation failure reason when enforcement denies an empty proof', function (): void {
        Config::set('mobile.attestation.enabled', true);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 1008;

        $request = Request::create('/api/send-money/store', 'POST', [
            'device_type'                      => 'ios',
            'device_id'                        => 'ios-device-registration-failed',
            'attestation_status'               => 'error',
            'attestation_capability_mode'      => 'none',
            'attestation_capability_reason'    => 'device_registration_failed',
            'attestation_capability_available' => false,
        ]);

        $result = $policy->evaluate($user, $request, 'send_money');

        expect($result['decision'])->toBe('deny')
            ->and($result['reason'])->toBe('attestation_device_registration_failed');
    });

    it('denies provider-error for enrolled ios device when attestation is enforced', function (): void {
        Config::set('mobile.attestation.enabled', true);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 100700 + random_int(1, 99999);
        DB::table('users')->insert([
            'id'       => $user->id,
            'uuid'     => (string) Str::uuid(),
            'name'     => 'Trust Policy Fallback User',
            'email'    => 'trust-fallback-' . $user->id . '@example.test',
            'password' => Hash::make('secret-1007'),
        ]);

        $deviceId = 'ios-provider-error-fallback-device-' . $user->id;
        DB::table('mobile_devices')->insert([
            'id'                 => (string) Str::uuid(),
            'user_id'            => $user->id,
            'device_id'          => $deviceId,
            'platform'           => 'ios',
            'app_version'        => '1.0.0',
            'biometric_enabled'  => false,
            'is_trusted'         => false,
            'is_blocked'         => false,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $request = Request::create('/api/v1/commerce/payments', 'POST', [
            'payment_link_token'               => 'good-token',
            'device_type'                      => 'ios',
            'device_id'                        => $deviceId,
            'attestation_status'               => 'error',
            'attestation_capability_mode'      => 'none',
            'attestation_capability_reason'    => 'provider_error',
            'attestation_capability_available' => false,
        ]);

        $result = $policy->evaluate($user, $request, 'commerce.payment.process');

        expect($result['decision'])->toBe('deny')
            ->and($result['reason'])->toBe('attestation_provider_error');
    });

    it('accepts ios-app-attest JSON envelope when enforcement is enabled and device_id matches', function (): void {
        Config::set('mobile.attestation.enabled', true);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $biometricJwtService->shouldNotReceive('verifyDeviceAttestation');

        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 2001;

        $deviceId = 'ios-test-device-uuid';
        $envelope = 'ios-app-attest:' . json_encode([
            'action'           => 'send-money',
            'deviceId'         => $deviceId,
            'keyId'            => 'key-1',
            'assertionReason'  => 'assertion_verified',
            'metadata'         => ['challenge_id' => 'ch-1'],
        ], JSON_THROW_ON_ERROR);

        $request = Request::create('/api/send-money/store', 'POST', [
            'payment_link_token'               => 'good-token',
            'device_type'                      => 'ios',
            'device_id'                        => $deviceId,
            'attestation'                      => $envelope,
            'attestation_status'               => 'collected',
            'attestation_capability_mode'      => 'app-attest',
            'attestation_capability_available' => true,
        ]);

        $result = $policy->evaluate($user, $request, 'send_money');

        expect($result['decision'])->toBe('allow')
            ->and($result['reason'])->toBe('attestation_verified')
            ->and($result['attestation_verified'])->toBeTrue();

        $persisted = DB::table('mobile_attestation_records')->where('id', $result['record_id'])->first();
        expect($persisted)->not->toBeNull();
        assert($persisted !== null);

        $metadata = json_decode((string) $persisted->metadata, true, 512, JSON_THROW_ON_ERROR);

        expect($metadata)
            ->toMatchArray([
                'attestation_present' => true,
                'app_attest' => [
                    'prefix'               => 'ios-app-attest',
                    'key_id_present'       => true,
                    'assertion_reason'     => 'assertion_verified',
                    'challenge_id_present' => true,
                ],
            ]);
    });

    it('accepts ios-app-attest envelope when assertionReason casing differs', function (): void {
        Config::set('mobile.attestation.enabled', true);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $biometricJwtService->shouldNotReceive('verifyDeviceAttestation');

        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 2003;

        $deviceId = 'ios-test-device-uuid-2';
        $envelope = 'ios-app-attest:' . json_encode([
            'deviceId'        => $deviceId,
            'assertionReason' => 'Assertion_Verified',
        ], JSON_THROW_ON_ERROR);

        $request = Request::create('/api/send-money/store', 'POST', [
            'device_type' => 'ios',
            'device_id'   => $deviceId,
            'attestation' => $envelope,
        ]);

        $result = $policy->evaluate($user, $request, 'send_money');

        expect($result['decision'])->toBe('allow')
            ->and($result['reason'])->toBe('attestation_verified');
    });

    it('denies ios-app-attest envelope when device_id does not match request', function (): void {
        Config::set('mobile.attestation.enabled', true);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $biometricJwtService->shouldNotReceive('verifyDeviceAttestation');

        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 2002;

        $envelope = 'ios-app-attest:' . json_encode([
            'deviceId'        => 'device-a',
            'keyId'           => 'key-1',
            'assertionReason' => 'assertion_verified',
        ], JSON_THROW_ON_ERROR);

        $request = Request::create('/api/send-money/store', 'POST', [
            'device_type' => 'ios',
            'device_id'   => 'device-b',
            'attestation' => $envelope,
        ]);

        $result = $policy->evaluate($user, $request, 'send_money');

        expect($result['decision'])->toBe('deny')
            ->and($result['reason'])->toBe('attestation_failed')
            ->and($result['attestation_verified'])->toBeFalse();
    });

    it('denies malformed ios-app-attest envelope when enforcement is enabled', function (): void {
        Config::set('mobile.attestation.enabled', true);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $biometricJwtService->shouldNotReceive('verifyDeviceAttestation');

        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 2004;

        $request = Request::create('/api/send-money/store', 'POST', [
            'device_type' => 'ios',
            'device_id'   => 'device-a',
            'attestation' => 'ios-app-attest:not-json',
        ]);

        $result = $policy->evaluate($user, $request, 'send_money');

        expect($result['decision'])->toBe('deny')
            ->and($result['reason'])->toBe('attestation_failed');

        $persisted = DB::table('mobile_attestation_records')->where('id', $result['record_id'])->first();
        expect($persisted)->not->toBeNull();
        assert($persisted !== null);

        $metadata = json_decode((string) $persisted->metadata, true, 512, JSON_THROW_ON_ERROR);

        expect($metadata)
            ->toMatchArray([
                'app_attest' => [
                    'prefix'      => 'ios-app-attest',
                    'parse_error' => 'invalid_json',
                ],
            ]);
    });

    it('denies ios-app-attest envelope with unverified assertion reason', function (): void {
        Config::set('mobile.attestation.enabled', true);

        /** @var BiometricJWTServiceInterface&Mockery\MockInterface $biometricJwtService */
        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $biometricJwtService->shouldNotReceive('verifyDeviceAttestation');

        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User();
        $user->id = 2005;

        $deviceId = 'ios-test-device-unverified';
        $envelope = 'ios-app-attest:' . json_encode([
            'deviceId'        => $deviceId,
            'keyId'           => 'key-1',
            'assertionReason' => 'assertion_signature_mismatch',
        ], JSON_THROW_ON_ERROR);

        $request = Request::create('/api/send-money/store', 'POST', [
            'device_type' => 'ios',
            'device_id'   => $deviceId,
            'attestation' => $envelope,
        ]);

        $result = $policy->evaluate($user, $request, 'send_money');

        expect($result['decision'])->toBe('deny')
            ->and($result['reason'])->toBe('attestation_failed');
    });
});
