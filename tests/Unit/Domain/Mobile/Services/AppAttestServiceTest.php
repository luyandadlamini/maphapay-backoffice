<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mobile\Services;

use App\Domain\Mobile\Contracts\AppAttestVerifierInterface;
use App\Domain\Mobile\DataObjects\AppAttestVerificationResult;
use App\Domain\Mobile\Models\MobileDevice;
use App\Domain\Mobile\Services\AppAttestService;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppAttestServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureAppAttestTables();
    }

    public function test_validates_assertion_prerequisites_against_active_key_and_pending_challenge(): void
    {
        $user = User::factory()->create();
        $device = MobileDevice::factory()->create([
            'user_id'   => $user->id,
            'device_id' => 'ios-app-attest-assert-device-' . Str::lower((string) Str::ulid()),
            'platform'  => 'ios',
        ]);

        $service = new AppAttestService(new class implements AppAttestVerifierInterface
        {
            public function verifyAttestation(string $attestationObject, string $challenge, string $keyId): AppAttestVerificationResult
            {
                return AppAttestVerificationResult::success();
            }

            public function verifyAssertion(string $assertion, string $challenge, string $keyId, string $publicKey): AppAttestVerificationResult
            {
                return AppAttestVerificationResult::success();
            }
        });

        $enrollmentChallenge = $service->issueChallenge($device, 'enrollment');
        $service->enrollKey(
            $device,
            $enrollmentChallenge->id,
            $enrollmentChallenge->plain_challenge,
            'ios-key-assertion',
            base64_encode(str_repeat('a', 160)),
        );

        $assertionChallenge = $service->issueChallenge($device, 'assertion', 'ios-key-assertion');

        $result = $service->validateAssertionPrerequisites(
            $device,
            $assertionChallenge->id,
            $assertionChallenge->plain_challenge,
            'ios-key-assertion',
        );

        $this->assertTrue($result->verified);
        $this->assertSame('assertion_prerequisites_verified', $result->reason);
    }

    public function test_rejects_assertion_prerequisites_for_unknown_key(): void
    {
        $user = User::factory()->create();
        $device = MobileDevice::factory()->create([
            'user_id'   => $user->id,
            'device_id' => 'ios-app-attest-missing-key-device-' . Str::lower((string) Str::ulid()),
            'platform'  => 'ios',
        ]);

        $service = new AppAttestService(new class implements AppAttestVerifierInterface
        {
            public function verifyAttestation(string $attestationObject, string $challenge, string $keyId): AppAttestVerificationResult
            {
                return AppAttestVerificationResult::success();
            }

            public function verifyAssertion(string $assertion, string $challenge, string $keyId, string $publicKey): AppAttestVerificationResult
            {
                return AppAttestVerificationResult::success();
            }
        });

        $assertionChallenge = $service->issueChallenge($device, 'assertion', 'missing-key');

        $result = $service->validateAssertionPrerequisites(
            $device,
            $assertionChallenge->id,
            $assertionChallenge->plain_challenge,
            'missing-key',
        );

        $this->assertFalse($result->verified);
        $this->assertSame('app_attest_key_not_found', $result->reason);
    }

    public function test_verifies_assertion_and_consumes_matching_challenge(): void
    {
        $user = User::factory()->create();
        $device = MobileDevice::factory()->create([
            'user_id'   => $user->id,
            'device_id' => 'ios-app-attest-verify-service-device-' . Str::lower((string) Str::ulid()),
            'platform'  => 'ios',
        ]);

        $service = new AppAttestService(new class implements AppAttestVerifierInterface
        {
            public function verifyAttestation(string $attestationObject, string $challenge, string $keyId): AppAttestVerificationResult
            {
                return AppAttestVerificationResult::success([
                    'public_key' => 'service-test-public-key',
                ]);
            }

            public function verifyAssertion(string $assertion, string $challenge, string $keyId, string $publicKey): AppAttestVerificationResult
            {
                return AppAttestVerificationResult::success([
                    'public_key' => $publicKey,
                ], 'assertion_verified');
            }
        });

        $enrollmentChallenge = $service->issueChallenge($device, 'enrollment');
        $service->enrollKey(
            $device,
            $enrollmentChallenge->id,
            $enrollmentChallenge->plain_challenge,
            'ios-key-service-verify',
            base64_encode(str_repeat('a', 160)),
        );

        $assertionChallenge = $service->issueChallenge($device, 'assertion', 'ios-key-service-verify');

        $result = $service->verifyAssertion(
            $device,
            $assertionChallenge->id,
            $assertionChallenge->plain_challenge,
            'ios-key-service-verify',
            base64_encode('assertion-payload'),
        );

        $this->assertTrue($result->verified);
        $this->assertSame('assertion_verified', $result->reason);
        $this->assertSame('service-test-public-key', $result->metadata['public_key']);

        $this->assertNotNull($device->appAttestKeys()
            ->where('key_id', 'ios-key-service-verify')
            ->value('last_assertion_at'));

        $this->assertNotNull($device->appAttestChallenges()
            ->where('id', $assertionChallenge->id)
            ->value('consumed_at'));
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
}
