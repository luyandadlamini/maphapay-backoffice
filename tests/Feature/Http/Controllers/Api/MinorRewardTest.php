<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorReward;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesApplication;

class MinorRewardTest extends BaseTestCase
{
    use CreatesApplication;

    private string $tenantId;

    private User $guardianUser;

    private User $coGuardianUser;

    private User $childUser;

    private User $strangerUser;

    private Account $guardianAccount;

    private Account $coGuardianAccount;

    private Account $minorAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        if (! Schema::hasTable('minor_rewards')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant/2026_04_20_099999_create_minor_rewards_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasColumn('minor_rewards', 'is_featured')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant/2026_04_20_100000_add_phase_8_columns_to_minor_rewards_table.php',
                '--force' => true,
            ]);
        }

        $this->tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id'            => $this->tenantId,
            'name'          => 'Minor Rewards Test Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        $this->guardianUser = User::factory()->create();
        $this->coGuardianUser = User::factory()->create();
        $this->childUser = User::factory()->create();
        $this->strangerUser = User::factory()->create();

        $this->guardianAccount = $this->createOwnedPersonalAccount($this->guardianUser);
        $this->coGuardianAccount = $this->createOwnedPersonalAccount($this->coGuardianUser);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'         => $this->childUser->uuid,
            'type'              => 'minor',
            'permission_level'  => 3,
            'parent_account_id' => $this->guardianAccount->id,
        ]);

        $this->createMinorMembership($this->guardianUser, $this->minorAccount, 'guardian');
        $this->createMinorMembership($this->coGuardianUser, $this->minorAccount, 'co_guardian');

        MinorReward::create([
            'id'                   => (string) Str::uuid(),
            'name'                 => 'Test Airtime',
            'description'          => 'Reward visible to authorized users',
            'points_cost'          => 100,
            'price_points'         => 100,
            'type'                 => 'airtime',
            'metadata'             => ['amount' => '50.00', 'provider' => 'MTN'],
            'stock'                => 5,
            'is_active'            => true,
            'is_featured'          => false,
            'min_permission_level' => 1,
        ]);
    }

    #[Test]
    public function guardian_can_view_rewards_catalog_via_real_membership(): void
    {
        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/rewards")
            ->assertOk()
            ->assertJsonPath('data.minor_account_uuid', $this->minorAccount->uuid)
            ->assertJsonFragment(['name' => 'Test Airtime']);
    }

    #[Test]
    public function co_guardian_can_view_rewards_catalog_via_real_membership(): void
    {
        Sanctum::actingAs($this->coGuardianUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/rewards")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Test Airtime']);
    }

    #[Test]
    public function child_can_view_own_rewards_catalog_when_guardian_membership_exists(): void
    {
        Sanctum::actingAs($this->childUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/rewards")
            ->assertOk()
            ->assertJsonPath('data.minor_account_uuid', $this->minorAccount->uuid);
    }

    #[Test]
    public function stranger_is_forbidden_from_viewing_rewards_catalog(): void
    {
        Sanctum::actingAs($this->strangerUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/rewards")
            ->assertForbidden();
    }

    #[Test]
    public function child_is_forbidden_when_minor_account_has_no_guardian_backing_membership(): void
    {
        $orphanChild = User::factory()->create();
        $orphanMinorAccount = Account::factory()->create([
            'user_uuid'         => $orphanChild->uuid,
            'type'              => 'minor',
            'permission_level'  => 3,
            'parent_account_id' => $this->guardianAccount->id,
        ]);

        Sanctum::actingAs($orphanChild, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$orphanMinorAccount->uuid}/rewards")
            ->assertForbidden();
    }

    private function createOwnedPersonalAccount(User $user): Account
    {
        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'type'      => 'personal',
        ]);

        AccountMembership::query()->create([
            'user_uuid'    => $user->uuid,
            'tenant_id'    => $this->tenantId,
            'account_uuid' => $account->uuid,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
            'joined_at'    => now(),
        ]);

        return $account;
    }

    private function createMinorMembership(User $user, Account $minorAccount, string $role): void
    {
        AccountMembership::query()->create([
            'user_uuid'    => $user->uuid,
            'tenant_id'    => $this->tenantId,
            'account_uuid' => $minorAccount->uuid,
            'account_type' => 'minor',
            'role'         => $role,
            'status'       => 'active',
            'joined_at'    => now(),
        ]);
    }
}
