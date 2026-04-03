<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class UserProfileControllerTest extends ControllerTestCase
{
    #[Test]
    public function it_disables_transaction_pin_requirement_without_removing_the_stored_pin(): void
    {
        $user = User::factory()->create([
            'transaction_pin' => '1234',
            'transaction_pin_enabled' => true,
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/v1/users/transaction-pin/toggle', [
            'enabled' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();

        $this->assertTrue($user->transaction_pin_set);
        $this->assertFalse($user->transaction_pin_enabled);
    }

    #[Test]
    public function it_reenables_transaction_pin_requirement_without_requiring_pin_reset_when_a_pin_exists(): void
    {
        $user = User::factory()->create([
            'transaction_pin' => '1234',
            'transaction_pin_enabled' => false,
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/v1/users/transaction-pin/toggle', [
            'enabled' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $user->refresh();

        $this->assertTrue($user->transaction_pin_set);
        $this->assertTrue($user->transaction_pin_enabled);
    }

    #[Test]
    public function auth_user_payload_exposes_transaction_pin_set_and_enabled_separately(): void
    {
        $user = User::factory()->create([
            'transaction_pin' => '1234',
            'transaction_pin_enabled' => false,
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/auth/user');

        $response->assertOk()
            ->assertJsonPath('data.transaction_pin_set', true)
            ->assertJsonPath('data.transaction_pin_enabled', false);
    }
}
