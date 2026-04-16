<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class MinorAccountControllerTest extends ControllerTestCase
{
    protected User $user;

    protected Account $parentAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        // Create a parent account for the user
        $this->parentAccount = Account::factory()->create([
            'user_uuid' => $this->user->uuid,
            'type' => 'personal',
        ]);

        // Create active membership for parent account
        AccountMembership::factory()->create([
            'user_uuid' => $this->user->uuid,
            'account_uuid' => $this->parentAccount->uuid,
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function test_valid_minor_account_creation_age_10(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(10)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Emma',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'account_uuid',
                    'account_type',
                    'name',
                    'tier',
                    'permission_level',
                    'parent_account_id',
                ]
            ]);

        // Verify the account was created
        $data = $response->json('data');
        $this->assertDatabaseHas('accounts', [
            'uuid' => $data['account_uuid'],
            'type' => 'minor',
            'name' => 'Emma',
        ]);

        // Verify membership was created with guardian role
        $this->assertDatabaseHas('account_memberships', [
            'account_uuid' => $data['account_uuid'],
            'user_uuid' => $this->user->uuid,
            'role' => 'guardian',
            'account_type' => 'minor',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function test_tier_assignment_grow_for_age_10(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(10)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Child',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals('grow', $response->json('data.tier'));
    }

    #[Test]
    public function test_tier_assignment_rise_for_age_14(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(14)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Teen',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals('rise', $response->json('data.tier'));
    }

    #[Test]
    public function test_permission_level_assignment_age_6(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(6)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Young',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(1, $response->json('data.permission_level'));
    }

    #[Test]
    public function test_permission_level_assignment_age_8(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(8)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Child',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(2, $response->json('data.permission_level'));
    }

    #[Test]
    public function test_permission_level_assignment_age_10(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(10)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Child',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(3, $response->json('data.permission_level'));
    }

    #[Test]
    public function test_permission_level_assignment_age_12(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(12)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Child',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(4, $response->json('data.permission_level'));
    }

    #[Test]
    public function test_permission_level_assignment_age_14(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(14)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Teen',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(5, $response->json('data.permission_level'));
    }

    #[Test]
    public function test_permission_level_assignment_age_16(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(16)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Teen',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(6, $response->json('data.permission_level'));
    }

    #[Test]
    public function test_invalid_age_too_young(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(5)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'TooYoung',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'errors' => [
                    'date_of_birth'
                ]
            ]);
    }

    #[Test]
    public function test_invalid_age_too_old(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(18)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'TooOld',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'errors' => [
                    'date_of_birth'
                ]
            ]);
    }

    #[Test]
    public function test_missing_name_validation(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(10)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'errors' => [
                    'name'
                ]
            ]);
    }

    #[Test]
    public function test_missing_date_of_birth_validation(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Emma',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'errors' => [
                    'date_of_birth'
                ]
            ]);
    }

    #[Test]
    public function test_requires_authentication(): void
    {
        $dateOfBirth = now()->subYears(10)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Emma',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function test_membership_created_with_correct_account_type(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(10)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Emma',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertDatabaseHas('account_memberships', [
            'account_uuid' => $data['account_uuid'],
            'user_uuid' => $this->user->uuid,
            'account_type' => 'minor',
            'role' => 'guardian',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function test_parent_account_id_is_authenticated_user_account(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(10)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name' => 'Emma',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $this->assertEquals($this->parentAccount->uuid, $data['parent_account_id']);
    }
}
