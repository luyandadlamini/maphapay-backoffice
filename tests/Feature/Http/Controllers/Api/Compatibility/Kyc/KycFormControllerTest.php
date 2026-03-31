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
            'kyc_status'          => 'partial_identity',
            'kyc_current_step'    => KycService::STEP_ADDRESS,
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
            ->assertJsonPath('remark', 'kyc_form')
            ->assertJsonPath('data.kyc_status', 'not_started')
            ->assertJsonPath('data.progress.status', 'not_started')
            ->assertJsonPath('data.form_available', true)
            ->assertJsonPath('data.current_step_form.step', KycService::STEP_ADDRESS);
    }

    #[Test]
    public function test_review_step_includes_actionable_fields(): void
    {
        $user = User::factory()->create([
            'kyc_status'          => 'partial_identity',
            'kyc_identity_type'   => 'national_id',
            'kyc_current_step'    => 'review',
            'kyc_steps_completed' => [
                KycService::STEP_IDENTITY_TYPE,
                KycService::STEP_IDENTITY_DOCUMENT,
                KycService::STEP_SELFIE,
                KycService::STEP_ADDRESS,
                KycService::STEP_ADDRESS_PROOF,
            ],
            'kyc_data' => [
                'address' => [
                    'address_line1' => '1 Test St',
                    'city'          => 'Mbabane',
                    'country'       => 'Eswatini',
                ],
            ],
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/kyc-form');

        $response->assertOk()
            ->assertJsonPath('data.current_step_form.step', 'review')
            ->assertJsonPath('data.current_step_form.fields.0.type', 'checkbox')
            ->assertJsonPath('data.current_step_form.primary_action.intent', 'finalize_kyc');
    }
}
