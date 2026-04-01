<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\Kyc;

use App\Domain\Compliance\Services\KycService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class KycSubmitControllerTest extends ControllerTestCase
{
    #[Test]
    public function test_identity_type_only_advances_to_document_step(): void
    {
        Storage::fake('private');

        $user = User::factory()->create([
            'kyc_status'          => 'not_started',
            'kyc_current_step'    => KycService::STEP_IDENTITY_TYPE,
            'kyc_steps_completed' => [],
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/kyc-submit', [
            'identity_type' => 'national_id',
        ]);

        $response->assertOk()
            ->assertJsonPath('remark', 'kyc_submit')
            ->assertJsonPath('data.current_step', KycService::STEP_IDENTITY_DOCUMENT);

        $user->refresh();
        $this->assertSame('national_id', $user->kyc_identity_type);
        $this->assertContains(KycService::STEP_IDENTITY_TYPE, $user->kyc_steps_completed ?? []);
    }

    #[Test]
    public function test_document_upload_after_type_selection_completes_identity_phase(): void
    {
        Storage::fake('private');

        $user = User::factory()->create([
            'kyc_status'          => 'not_started',
            'kyc_identity_type'   => 'national_id',
            'kyc_current_step'    => KycService::STEP_IDENTITY_DOCUMENT,
            'kyc_steps_completed' => [KycService::STEP_IDENTITY_TYPE],
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->post('/api/kyc-submit', [
            'national_id' => UploadedFile::fake()->image('nid.jpg', 800, 600),
            'selfie'      => UploadedFile::fake()->image('selfie.jpg', 600, 600),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.current_step', KycService::STEP_ADDRESS)
            ->assertJsonPath('data.kyc_status', 'pending');

        $user->refresh();
        $this->assertSame('partial_identity', $user->kyc_status);
    }

    #[Test]
    public function test_rejects_submit_when_kyc_pending(): void
    {
        $user = User::factory()->create([
            'kyc_status'       => 'pending',
            'kyc_current_step' => 'pending',
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $this->postJson('/api/kyc-submit', ['identity_type' => 'national_id'])
            ->assertStatus(400)
            ->assertJsonPath('remark', 'kyc_submit');
    }

    #[Test]
    public function test_returns_validation_error_when_identity_step_state_is_invalid(): void
    {
        Storage::fake('private');

        $user = User::factory()->create([
            'kyc_status'          => 'not_started',
            'kyc_identity_type'   => 'national_id',
            'kyc_current_step'    => KycService::STEP_IDENTITY_DOCUMENT,
            'kyc_steps_completed' => [],
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->post('/api/kyc-submit', [
            'national_id' => UploadedFile::fake()->image('nid.jpg', 800, 600),
            'selfie'      => UploadedFile::fake()->image('selfie.jpg', 600, 600),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('remark', 'kyc_submit')
            ->assertJsonPath('status', 'error');
    }
}
