<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Compatibility;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\ControllerTestCase;

class UserExistTest extends ControllerTestCase
{
    protected function connectionsToTransact(): array
    {
        return ['central'];
    }

    public function test_user_exist_returns_recipient_for_known_username(): void
    {
        $caller = User::factory()->create();
        $recipient = User::factory()->create(['username' => 'mickey.dacunha']);

        Sanctum::actingAs($caller, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/user/exist', ['user' => 'mickey.dacunha']);

        $response->assertOk()
            ->assertJsonStructure(['status', 'data' => ['id', 'username', 'display_name']])
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.username', 'mickey.dacunha')
            ->assertJsonPath('data.id', $recipient->id);
    }

    public function test_user_exist_returns_error_envelope_for_unknown_user(): void
    {
        $caller = User::factory()->create();
        Sanctum::actingAs($caller, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/user/exist', ['user' => 'nobody.here']);

        $response->assertOk() // compat envelope: 200 with status=error per CLAUDE.md
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('data', null);
    }

    public function test_user_exist_rejects_self_lookup(): void
    {
        $caller = User::factory()->create(['username' => 'self.user']);
        Sanctum::actingAs($caller, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/user/exist', ['user' => 'self.user']);

        $response->assertOk()
            ->assertJsonPath('status', 'error');
    }

    public function test_user_exist_excludes_frozen_users(): void
    {
        $caller = User::factory()->create();
        User::factory()->create(['username' => 'frozen.one', 'frozen_at' => now()]);

        Sanctum::actingAs($caller, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/user/exist', ['user' => 'frozen.one']);

        $response->assertOk()->assertJsonPath('status', 'error');
    }

    public function test_user_exist_finds_by_mobile_number(): void
    {
        $caller = User::factory()->create();
        $recipient = User::factory()->create([
            'username' => 'phone.user',
            'mobile'   => '+26876543210',
        ]);

        Sanctum::actingAs($caller, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/user/exist', ['user' => '+26876543210']);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $recipient->id);
    }

    public function test_user_exist_requires_authentication(): void
    {
        $this->postJson('/api/user/exist', ['user' => 'anyone'])
            ->assertUnauthorized();
    }

    public function test_user_exist_validates_user_field(): void
    {
        $caller = User::factory()->create();
        Sanctum::actingAs($caller, ['read', 'write', 'delete']);

        $this->postJson('/api/user/exist', [])->assertStatus(422);
    }
}
