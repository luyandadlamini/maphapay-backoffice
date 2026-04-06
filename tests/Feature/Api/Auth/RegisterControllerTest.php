<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class RegisterControllerTest extends ControllerTestCase
{
    #[Test]
    public function test_user_can_register_via_api(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'John Doe',
            'email'                 => 'john@example.com',
            'password'              => 'Test@Pass2024!Secure',
            'password_confirmation' => 'Test@Pass2024!Secure',
            'is_business_customer'  => false,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                    ],
                    'access_token',
                    'refresh_token',
                    'token_type',
                    'expires_in',
                    'refresh_expires_in',
                ],
            ])
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'name'  => 'John Doe',
            'email' => 'john@example.com',
        ]);

        // Verify user has the correct role
        $user = User::where('email', 'john@example.com')->first();
        $this->assertTrue($user->hasRole('customer_private'));
    }

    #[Test]
    public function test_business_user_can_register_via_api(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'Business User',
            'email'                 => 'business@example.com',
            'password'              => 'Test@Pass2024!Secure',
            'password_confirmation' => 'Test@Pass2024!Secure',
            'is_business_customer'  => true,
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'business@example.com')->first();
        $this->assertTrue($user->hasRole('customer_business'));
    }

    #[Test]
    public function test_registration_validates_input(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                  => '',
            'email'                 => 'invalid-email',
            'password'              => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    #[Test]
    public function test_registration_prevents_duplicate_emails(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'John Doe',
            'email'                 => 'existing@example.com',
            'password'              => 'Test@Pass2024!Secure',
            'password_confirmation' => 'Test@Pass2024!Secure',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function test_registered_user_receives_access_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name'                  => 'John Doe',
            'email'                 => 'john@example.com',
            'password'              => 'Test@Pass2024!Secure',
            'password_confirmation' => 'Test@Pass2024!Secure',
        ]);

        $response->assertStatus(201);
        $token = $response->json('data.access_token');
        $this->assertNotEmpty($token);

        // Test that the token works
        $authResponse = $this->withToken($token)->getJson('/api/auth/user');
        $authResponse->assertOk()
            ->assertJsonPath('data.email', 'john@example.com');
    }
}
