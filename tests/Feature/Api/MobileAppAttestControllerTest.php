<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Mobile\Contracts\AppAttestVerifierInterface;
use App\Domain\Mobile\DataObjects\AppAttestVerificationResult;
use App\Domain\Mobile\Models\MobileDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MobileAppAttestControllerTest extends TestCase
{
    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureAppAttestTables();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token', ['read', 'write', 'delete'])->plainTextToken;
    }

    private function ensureAppAttestTables(): void
    {
        if (! Schema::hasTable('mobile_app_attest_keys')) {
            Schema::create('mobile_app_attest_keys', function ($table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->uuid('mobile_device_id');
                $table->string('key_id', 191);
                $table->string('status', 32)->default('active');
                $table->timestamp('attested_at')->nullable();
                $table->timestamp('last_assertion_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['mobile_device_id', 'key_id'], 'mobile_app_attest_keys_device_key_unique');
            });
        }

        if (! Schema::hasTable('mobile_app_attest_challenges')) {
            Schema::create('mobile_app_attest_challenges', function ($table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('user_id');
                $table->uuid('mobile_device_id');
                $table->uuid('mobile_app_attest_key_id')->nullable();
                $table->string('purpose', 32);
                $table->string('key_id', 191)->nullable();
                $table->string('challenge_hash', 64);
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_can_issue_app_attest_enrollment_challenge_for_owned_ios_device(): void
    {
        $device = MobileDevice::factory()->create([
            'user_id'   => $this->user->id,
            'device_id' => 'ios-app-attest-device',
            'platform'  => 'ios',
        ]);

        $response = $this->withToken($this->token)->postJson('/api/mobile/auth/attestation/app-attest/challenge', [
            'device_id' => $device->device_id,
            'purpose'   => 'enrollment',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.device_id', $device->device_id)
            ->assertJsonPath('data.purpose', 'enrollment')
            ->assertJsonPath('data.rollout_enabled', false)
            ->assertJsonStructure([
                'data' => [
                    'challenge_id',
                    'challenge',
                    'device_id',
                    'purpose',
                    'expires_at',
                    'rollout_enabled',
                ],
            ]);

        $this->assertDatabaseHas('mobile_app_attest_challenges', [
            'mobile_device_id' => $device->id,
            'user_id'          => $this->user->id,
            'purpose'          => 'enrollment',
        ]);
    }

    public function test_can_enroll_app_attest_key_with_matching_challenge_and_persist_key_record(): void
    {
        $device = MobileDevice::factory()->create([
            'user_id'   => $this->user->id,
            'device_id' => 'ios-app-attest-enroll-device',
            'platform'  => 'ios',
        ]);

        $this->app->instance(AppAttestVerifierInterface::class, new class () implements AppAttestVerifierInterface {
            public function verifyAttestation(string $attestationObject, string $challenge, string $keyId): AppAttestVerificationResult
            {
                return AppAttestVerificationResult::success([
                    'verified_via' => 'feature-test-double',
                ]);
            }

            public function verifyAssertion(
                string $assertion,
                string $challenge,
                string $keyId,
                string $credentialPublicKeyHex,
                ?int $lastAcceptedSignCount = null,
            ): AppAttestVerificationResult {
                return AppAttestVerificationResult::failure('not_implemented_for_test');
            }
        });

        $challengeResponse = $this->withToken($this->token)->postJson('/api/mobile/auth/attestation/app-attest/challenge', [
            'device_id' => $device->device_id,
            'purpose'   => 'enrollment',
        ]);

        $challengeResponse->assertOk();

        $challengeId = (string) $challengeResponse->json('data.challenge_id');
        $challenge = (string) $challengeResponse->json('data.challenge');
        $keyId = 'ios-key-001';

        $response = $this->withToken($this->token)->postJson('/api/mobile/auth/attestation/app-attest/enroll', [
            'device_id'          => $device->device_id,
            'challenge_id'       => $challengeId,
            'challenge'          => $challenge,
            'key_id'             => $keyId,
            'attestation_object' => base64_encode(str_repeat('a', 160)),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.device_id', $device->device_id)
            ->assertJsonPath('data.key_id', $keyId)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.rollout_enabled', false);

        $this->assertDatabaseHas('mobile_app_attest_keys', [
            'mobile_device_id' => $device->id,
            'user_id'          => $this->user->id,
            'key_id'           => $keyId,
            'status'           => 'active',
        ]);

        $consumedAt = DB::table('mobile_app_attest_challenges')
            ->where('id', $challengeId)
            ->value('consumed_at');

        $this->assertNotNull($consumedAt);
    }

    public function test_rejects_replayed_app_attest_enrollment_challenge(): void
    {
        $device = MobileDevice::factory()->create([
            'user_id'   => $this->user->id,
            'device_id' => 'ios-app-attest-replay-device',
            'platform'  => 'ios',
        ]);

        $this->app->instance(AppAttestVerifierInterface::class, new class () implements AppAttestVerifierInterface {
            public function verifyAttestation(string $attestationObject, string $challenge, string $keyId): AppAttestVerificationResult
            {
                return AppAttestVerificationResult::success();
            }

            public function verifyAssertion(
                string $assertion,
                string $challenge,
                string $keyId,
                string $credentialPublicKeyHex,
                ?int $lastAcceptedSignCount = null,
            ): AppAttestVerificationResult {
                return AppAttestVerificationResult::failure('not_implemented_for_test');
            }
        });

        $challengeResponse = $this->withToken($this->token)->postJson('/api/mobile/auth/attestation/app-attest/challenge', [
            'device_id' => $device->device_id,
            'purpose'   => 'enrollment',
        ]);

        $challengeId = (string) $challengeResponse->json('data.challenge_id');
        $challenge = (string) $challengeResponse->json('data.challenge');

        $payload = [
            'device_id'          => $device->device_id,
            'challenge_id'       => $challengeId,
            'challenge'          => $challenge,
            'key_id'             => 'ios-key-replay',
            'attestation_object' => base64_encode(str_repeat('a', 160)),
        ];

        $this->withToken($this->token)
            ->postJson('/api/mobile/auth/attestation/app-attest/enroll', $payload)
            ->assertCreated();

        $this->withToken($this->token)
            ->postJson('/api/mobile/auth/attestation/app-attest/enroll', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_APP_ATTEST_CHALLENGE');
    }

    public function test_can_verify_app_attest_assertion_for_enrolled_key_and_consume_challenge(): void
    {
        $device = MobileDevice::factory()->create([
            'user_id'   => $this->user->id,
            'device_id' => 'ios-app-attest-verify-device',
            'platform'  => 'ios',
        ]);

        $this->app->instance(AppAttestVerifierInterface::class, new class () implements AppAttestVerifierInterface {
            public function verifyAttestation(string $attestationObject, string $challenge, string $keyId): AppAttestVerificationResult
            {
                return AppAttestVerificationResult::success([
                    'credential_public_key_hex' => '04' . str_repeat('cd', 64),
                    'public_key'                => '04' . str_repeat('cd', 64),
                    'attestation_sign_count'    => 0,
                ]);
            }

            public function verifyAssertion(
                string $assertion,
                string $challenge,
                string $keyId,
                string $credentialPublicKeyHex,
                ?int $lastAcceptedSignCount = null,
            ): AppAttestVerificationResult {
                return AppAttestVerificationResult::success([
                    'verified_via' => 'feature-test-double',
                    'public_key'   => $credentialPublicKeyHex,
                    'sign_count'   => 1,
                ], 'assertion_verified');
            }
        });

        $enrollmentChallengeResponse = $this->withToken($this->token)->postJson('/api/mobile/auth/attestation/app-attest/challenge', [
            'device_id' => $device->device_id,
            'purpose'   => 'enrollment',
        ]);

        $this->withToken($this->token)->postJson('/api/mobile/auth/attestation/app-attest/enroll', [
            'device_id'          => $device->device_id,
            'challenge_id'       => (string) $enrollmentChallengeResponse->json('data.challenge_id'),
            'challenge'          => (string) $enrollmentChallengeResponse->json('data.challenge'),
            'key_id'             => 'ios-key-verify',
            'attestation_object' => base64_encode(str_repeat('a', 160)),
        ])->assertCreated();

        $assertionChallengeResponse = $this->withToken($this->token)->postJson('/api/mobile/auth/attestation/app-attest/challenge', [
            'device_id' => $device->device_id,
            'purpose'   => 'assertion',
            'key_id'    => 'ios-key-verify',
        ]);

        $assertionChallengeId = (string) $assertionChallengeResponse->json('data.challenge_id');
        $assertionChallenge = (string) $assertionChallengeResponse->json('data.challenge');

        $response = $this->withToken($this->token)->postJson('/api/mobile/auth/attestation/app-attest/verify', [
            'device_id'    => $device->device_id,
            'challenge_id' => $assertionChallengeId,
            'challenge'    => $assertionChallenge,
            'key_id'       => 'ios-key-verify',
            'assertion'    => base64_encode('assertion-payload'),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.device_id', $device->device_id)
            ->assertJsonPath('data.key_id', 'ios-key-verify')
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.reason', 'assertion_verified')
            ->assertJsonPath('data.rollout_enabled', false);

        $this->assertNotNull(DB::table('mobile_app_attest_keys')
            ->where('mobile_device_id', $device->id)
            ->where('key_id', 'ios-key-verify')
            ->value('last_assertion_at'));

        $this->assertNotNull(DB::table('mobile_app_attest_challenges')
            ->where('id', $assertionChallengeId)
            ->value('consumed_at'));
    }
}
