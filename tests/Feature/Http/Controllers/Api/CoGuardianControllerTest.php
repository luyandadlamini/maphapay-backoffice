<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CoGuardianControllerTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return ['mysql', 'central'];
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    private User $guardian;

    private User $coGuardian;

    private Account $minorAccount;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        if (! Schema::connection('central')->hasTable('guardian_invites')) {
            Artisan::call('migrate', [
                '--database' => 'central',
                '--path'     => 'database/migrations/2026_04_16_130000_create_guardian_invites_table.php',
                '--force'    => true,
            ]);
        }

        $this->guardian = User::factory()->create();
        $this->coGuardian = User::factory()->create();
        $this->tenantId = (string) Str::uuid();

        DB::connection('central')->table('tenants')->insert([
            'id'            => $this->tenantId,
            'name'          => 'Minor Accounts Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'        => $this->guardian->uuid,
            'type'             => 'minor',
            'permission_level' => 3,
            'tier'             => 'grow',
        ]);

        AccountMembership::query()->create([
            'user_uuid'    => $this->guardian->uuid,
            'tenant_id'    => $this->tenantId,
            'account_uuid' => $this->minorAccount->uuid,
            'account_type' => 'minor',
            'role'         => 'guardian',
            'status'       => 'active',
            'joined_at'    => now(),
        ]);
    }

    #[Test]
    public function primary_guardian_can_generate_an_invite_code(): void
    {
        Sanctum::actingAs($this->guardian, ['read', 'write', 'delete']);

        $response = $this
            ->withHeaders(['X-Account-Id' => $this->minorAccount->uuid])
            ->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/invite-co-guardian");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['code', 'expires_at'],
            ]);

        $this->assertDatabaseHas('guardian_invites', [
            'minor_account_uuid'   => $this->minorAccount->uuid,
            'invited_by_user_uuid' => $this->guardian->uuid,
            'status'               => 'pending',
        ], 'central');
    }

    #[Test]
    public function co_guardian_cannot_generate_an_invite_code(): void
    {
        AccountMembership::query()->create([
            'user_uuid'    => $this->coGuardian->uuid,
            'tenant_id'    => $this->tenantId,
            'account_uuid' => $this->minorAccount->uuid,
            'account_type' => 'minor',
            'role'         => 'co_guardian',
            'status'       => 'active',
            'joined_at'    => now(),
        ]);

        Sanctum::actingAs($this->coGuardian, ['read', 'write', 'delete']);

        $response = $this
            ->withHeaders(['X-Account-Id' => $this->minorAccount->uuid])
            ->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/invite-co-guardian");

        $response->assertForbidden();
    }

    #[Test]
    public function authenticated_user_can_accept_an_active_guardian_invite(): void
    {
        $code = strtoupper(Str::random(8));

        DB::connection('central')->table('guardian_invites')->insert([
            'id'                   => (string) Str::uuid(),
            'minor_account_uuid'   => $this->minorAccount->uuid,
            'invited_by_user_uuid' => $this->guardian->uuid,
            'code'                 => $code,
            'expires_at'           => now()->addHours(72),
            'claimed_at'           => null,
            'claimed_by_user_uuid' => null,
            'status'               => 'pending',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        Sanctum::actingAs($this->coGuardian, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/guardian-invites/{$code}/accept");

        $response->assertOk()
            ->assertJsonPath('data.role', 'co_guardian')
            ->assertJsonPath('data.account_uuid', $this->minorAccount->uuid);

        $this->assertDatabaseHas('account_memberships', [
            'user_uuid'    => $this->coGuardian->uuid,
            'account_uuid' => $this->minorAccount->uuid,
            'role'         => 'co_guardian',
            'status'       => 'active',
        ], 'central');

        $this->assertDatabaseHas('guardian_invites', [
            'code'                 => $code,
            'status'               => 'claimed',
            'claimed_by_user_uuid' => $this->coGuardian->uuid,
        ], 'central');
    }

    #[Test]
    public function expired_invites_cannot_be_accepted(): void
    {
        $code = strtoupper(Str::random(8));

        DB::connection('central')->table('guardian_invites')->insert([
            'id'                   => (string) Str::uuid(),
            'minor_account_uuid'   => $this->minorAccount->uuid,
            'invited_by_user_uuid' => $this->guardian->uuid,
            'code'                 => $code,
            'expires_at'           => now()->subMinute(),
            'claimed_at'           => null,
            'claimed_by_user_uuid' => null,
            'status'               => 'pending',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        Sanctum::actingAs($this->coGuardian, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/guardian-invites/{$code}/accept");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'This invite code has expired.');
    }
}
