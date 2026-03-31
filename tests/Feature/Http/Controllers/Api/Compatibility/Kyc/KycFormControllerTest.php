<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\Kyc;

use App\Domain\Compliance\Services\KycService;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class KycFormControllerTest extends ControllerTestCase
{
    #[Test]
    public function test_partial_identity_status_is_normalized_for_compat_clients(): void
    {
        $user = User::factory()->create([
            'kyc_status' => 'partial_identity',
            'kyc_current_step' => KycService::STEP_ADDRESS,
            'kyc_steps_completed' => [
                KycService::STEP_IDENTITY_TYPE,
                KycService::STEP_IDENTITY_DOCUMENT,
                KycService::STEP_SELFIE,
            ],
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/kyc-form');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.kyc_status', 'not_started')
            ->assertJsonPath('data.form_available', true)
            ->assertJsonPath('data.current_step_form.step', KycService::STEP_ADDRESS);
    }
}

