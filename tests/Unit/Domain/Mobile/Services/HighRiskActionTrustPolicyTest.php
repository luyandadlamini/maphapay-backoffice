<?php

declare(strict_types=1);

use App\Domain\Mobile\Contracts\BiometricJWTServiceInterface;
use App\Domain\Mobile\Services\HighRiskActionTrustPolicy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User;
        $user->id = 1001;

        $request = Request::create('/api/v1/commerce/payments', 'POST', [
            'payment_link_token' => 'good-token',
        ]);

        $result = $policy->evaluate($user, $request, 'commerce.payment.process');

        expect($result['decision'])->toBe('degrade')
            ->and($result['reason'])->toBe('attestation_disabled_device_untrusted')
            ->and($result['record_id'])->not->toBe('');

        $persisted = DB::table('mobile_attestation_records')->where('id', $result['record_id'])->first();
        expect($persisted)->not->toBeNull()
            ->and($persisted->decision)->toBe('degrade')
            ->and($persisted->action)->toBe('commerce.payment.process');
    });

    it('persists a deny decision when attestation is enabled but missing', function (): void {
        Config::set('mobile.attestation.enabled', true);

        $biometricJwtService = Mockery::mock(BiometricJWTServiceInterface::class);
        $policy = new HighRiskActionTrustPolicy($biometricJwtService);

        $user = new User;
        $user->id = 1002;

        $request = Request::create('/api/v1/commerce/payments', 'POST', [
            'payment_link_token' => 'good-token',
            'device_type' => 'ios',
        ]);

        $result = $policy->evaluate($user, $request, 'commerce.payment.process');

        expect($result['decision'])->toBe('deny')
            ->and($result['reason'])->toBe('attestation_required');

        $persisted = DB::table('mobile_attestation_records')->where('id', $result['record_id'])->first();
        expect($persisted)->not->toBeNull()
            ->and($persisted->attestation_enabled)->toBe(1)
            ->and($persisted->attestation_verified)->toBe(0)
            ->and($persisted->reason)->toBe('attestation_required');
    });
});
