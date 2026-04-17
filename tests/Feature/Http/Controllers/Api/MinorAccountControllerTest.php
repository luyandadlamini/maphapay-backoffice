<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Http\Middleware\ResolveAccountContext;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesApplication;

class MinorAccountControllerTest extends BaseTestCase
{
    use CreatesApplication;

    protected User $user;

    protected Account $parentAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ResolveAccountContext::class);

        $this->user = User::factory()->create();
        $this->parentAccount = Account::factory()->create([
            'user_uuid'    => $this->user->uuid,
            'account_type' => 'personal',
        ]);

        // Create active membership for parent account
        $tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id'            => $tenantId,
            'name'          => 'Test Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        AccountMembership::create([
            'user_uuid'    => $this->user->uuid,
            'account_uuid' => $this->parentAccount->uuid,
            'tenant_id'    => $tenantId,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
        ]);
    }

    #[Test]
    public function test_valid_minor_account_creation_age_10(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(10)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'Emma',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'account' => [
                        'uuid',
                        'account_type',
                        'name',
                        'account_tier',
                        'permission_level',
                        'parent_account_id',
                    ],
                    'membership' => [
                        'account_uuid',
                        'user_uuid',
                        'role',
                        'status',
                        'account_type',
                    ],
                ],
            ]);

        $data = $response->json('data.account');
        $this->assertDatabaseHas('accounts', [
            'uuid'         => $data['uuid'],
            'account_type' => 'minor',
            'name'         => 'Emma',
        ]);

        $this->assertDatabaseHas('account_memberships', [
            'account_uuid' => $data['uuid'],
            'user_uuid'    => $this->user->uuid,
            'role'         => 'guardian',
            'account_type' => 'minor',
            'status'       => 'active',
        ]);
    }

    #[Test]
    public function test_tier_assignment_grow_for_age_10(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(10)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'Child',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals('grow', $response->json('data.account.account_tier'));
    }

    #[Test]
    public function test_tier_assignment_rise_for_age_14(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(14)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'Teen',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals('rise', $response->json('data.account.account_tier'));
    }

    #[Test]
    public function test_permission_level_assignment_age_6(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(6)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'Young',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(1, $response->json('data.account.permission_level'));
    }

    #[Test]
    public function test_permission_level_assignment_age_8(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(8)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'Child',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(2, $response->json('data.account.permission_level'));
    }

    #[Test]
    public function test_permission_level_assignment_age_10(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(10)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'Child',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(3, $response->json('data.account.permission_level'));
    }

    #[Test]
    public function test_permission_level_assignment_age_12(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(12)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'Child',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(4, $response->json('data.account.permission_level'));
    }

    #[Test]
    public function test_permission_level_assignment_age_14(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(14)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'Teen',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(5, $response->json('data.account.permission_level'));
    }

    #[Test]
    public function test_permission_level_assignment_age_16(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(16)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'Teen',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(6, $response->json('data.account.permission_level'));
    }

    #[Test]
    public function test_invalid_age_too_young(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(5)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'TooYoung',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'errors' => [
                    'date_of_birth',
                ],
            ]);
    }

    #[Test]
    public function test_invalid_age_too_old(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(18)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'TooOld',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'errors' => [
                    'date_of_birth',
                ],
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
                    'name',
                ],
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
                    'date_of_birth',
                ],
            ]);
    }

    #[Test]
    public function test_requires_authentication(): void
    {
        $dateOfBirth = now()->subYears(10)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'Emma',
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
            'name'          => 'Emma',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $data = $response->json('data.membership');

        $this->assertDatabaseHas('account_memberships', [
            'account_uuid' => $data['account_uuid'],
            'user_uuid'    => $this->user->uuid,
            'account_type' => 'minor',
            'role'         => 'guardian',
            'status'       => 'active',
        ]);
    }

    #[Test]
    public function test_parent_account_id_is_authenticated_user_account(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $dateOfBirth = now()->subYears(10)->format('Y-m-d');

        $response = $this->postJson('/api/accounts/minor', [
            'name'          => 'Emma',
            'date_of_birth' => $dateOfBirth,
        ]);

        $response->assertStatus(201);
        $data = $response->json('data.account');

        $this->assertEquals($this->parentAccount->uuid, $data['parent_account_id']);
    }

    #[Test]
    public function test_primary_guardian_can_update_permission_level(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $createResponse = $this->postJson('/api/accounts/minor', [
            'name'          => 'Emma',
            'date_of_birth' => now()->subYears(10)->format('Y-m-d'),
        ]);

        $createResponse->assertStatus(201);
        $minorAccountUuid = (string) $createResponse->json('data.account.uuid');

        $response = $this
            ->withHeaders(['X-Account-Id' => $minorAccountUuid])
            ->putJson("/api/accounts/minor/{$minorAccountUuid}/permission-level", [
                'permission_level' => 4,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.permission_level', 4)
            ->assertJsonPath('data.uuid', $minorAccountUuid);
    }

    #[Test]
    public function test_permission_level_cannot_be_demoted(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $createResponse = $this->postJson('/api/accounts/minor', [
            'name'          => 'Emma',
            'date_of_birth' => now()->subYears(14)->format('Y-m-d'),
        ]);

        $minorAccountUuid = (string) $createResponse->json('data.account.uuid');

        $response = $this
            ->withHeaders(['X-Account-Id' => $minorAccountUuid])
            ->putJson("/api/accounts/minor/{$minorAccountUuid}/permission-level", [
                'permission_level' => 4,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permission_level']);
    }

    #[Test]
    public function test_grow_tier_cannot_exceed_permission_level_four(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $createResponse = $this->postJson('/api/accounts/minor', [
            'name'          => 'Emma',
            'date_of_birth' => now()->subYears(10)->format('Y-m-d'),
        ]);

        $minorAccountUuid = (string) $createResponse->json('data.account.uuid');

        $response = $this
            ->withHeaders(['X-Account-Id' => $minorAccountUuid])
            ->putJson("/api/accounts/minor/{$minorAccountUuid}/permission-level", [
                'permission_level' => 5,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['permission_level']);
    }

    #[Test]
    public function test_co_guardian_cannot_update_permission_level(): void
    {
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        $createResponse = $this->postJson('/api/accounts/minor', [
            'name'          => 'Emma',
            'date_of_birth' => now()->subYears(14)->format('Y-m-d'),
        ]);

        $minorAccountUuid = (string) $createResponse->json('data.account.uuid');
        $coGuardian = User::factory()->create();

        AccountMembership::query()->create([
            'user_uuid'    => $coGuardian->uuid,
            'account_uuid' => $minorAccountUuid,
            'tenant_id'    => AccountMembership::query()
                ->where('account_uuid', $minorAccountUuid)
                ->value('tenant_id'),
            'account_type' => 'minor',
            'role'         => 'co_guardian',
            'status'       => 'active',
            'joined_at'    => now(),
        ]);

        Sanctum::actingAs($coGuardian, ['read', 'write', 'delete']);

        $response = $this
            ->withHeaders(['X-Account-Id' => $minorAccountUuid])
            ->putJson("/api/accounts/minor/{$minorAccountUuid}/permission-level", [
                'permission_level' => 6,
            ]);

        $response->assertForbidden();
    }
}
