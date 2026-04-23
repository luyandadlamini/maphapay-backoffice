<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Services\MinorAccountLifecyclePolicy;
use App\Domain\User\Models\UserProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorAccountLifecyclePolicyTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->runLifecycleMigrations();
        $this->ensureCentralTenantExists('tenant-lifecycle');
    }

    private function ensureCentralTenantExists(string $tenantId): void
    {
        $central = DB::connection('central');
        if ($central->table('tenants')->where('id', $tenantId)->exists()) {
            return;
        }

        $central->table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Lifecycle policy test tenant',
            'plan' => 'default',
            'team_id' => null,
            'trial_ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'data' => json_encode([]),
        ]);
    }

    #[Test]
    public function it_detects_tier_advancement_for_a_grow_account_turning_13(): void
    {
        $child = User::factory()->create(['kyc_status' => 'approved']);
        $account = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type' => 'minor',
            'tier' => 'grow',
            'permission_level' => 3,
        ]);

        UserProfile::query()->create([
            'user_id' => $child->id,
            'email' => $child->email,
            'first_name' => 'Child',
            'status' => 'active',
            'date_of_birth' => now()->subYears(13)->toDateString(),
            'is_verified' => false,
        ]);

        $result = app(MinorAccountLifecyclePolicy::class)->evaluateTierAdvance($account);

        $this->assertTrue($result['eligible']);
        $this->assertSame('rise', $result['target_tier']);
        $this->assertSame(4, $result['target_permission_level']);
    }

    #[Test]
    public function it_blocks_adult_transition_when_kyc_is_not_ready(): void
    {
        $child = User::factory()->create(['kyc_status' => 'pending']);
        $account = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type' => 'minor',
            'tier' => 'rise',
            'permission_level' => 6,
        ]);

        UserProfile::query()->create([
            'user_id' => $child->id,
            'email' => $child->email,
            'first_name' => 'Teen',
            'status' => 'active',
            'date_of_birth' => now()->subYears(18)->toDateString(),
            'is_verified' => false,
        ]);

        $result = app(MinorAccountLifecyclePolicy::class)->evaluateAdultTransition($account);

        $this->assertFalse($result['ready']);
        $this->assertSame(MinorAccountLifecyclePolicy::REASON_ADULT_KYC_NOT_READY, $result['reason_code']);
    }

    #[Test]
    public function it_detects_broken_guardian_continuity_when_all_guardians_are_frozen(): void
    {
        $child = User::factory()->create();
        $guardian = User::factory()->create(['frozen_at' => now()]);
        $account = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type' => 'minor',
            'tier' => 'grow',
        ]);

        AccountMembership::query()->create([
            'tenant_id' => 'tenant-lifecycle',
            'account_uuid' => $account->uuid,
            'user_uuid' => $guardian->uuid,
            'role' => 'guardian',
            'status' => 'active',
            'account_type' => 'minor',
        ]);

        $result = app(MinorAccountLifecyclePolicy::class)->evaluateGuardianContinuity($account);

        $this->assertFalse($result['valid']);
        $this->assertSame(MinorAccountLifecyclePolicy::REASON_GUARDIAN_CONTINUITY_BROKEN, $result['reason_code']);
    }

    private function runLifecycleMigrations(): void
    {
        if (! Schema::hasTable('minor_account_lifecycle_transitions')) {
            (require base_path('database/migrations/tenant/2026_04_23_110000_create_minor_account_lifecycle_transitions_table.php'))->up();
        }
        if (! Schema::hasTable('minor_account_lifecycle_exceptions')) {
            (require base_path('database/migrations/tenant/2026_04_23_110100_create_minor_account_lifecycle_exceptions_table.php'))->up();
        }
        if (! Schema::hasTable('minor_account_lifecycle_exception_acknowledgments')) {
            (require base_path('database/migrations/tenant/2026_04_23_110110_create_minor_account_lifecycle_exception_acknowledgments_table.php'))->up();
        }
        if (! Schema::hasColumn('accounts', 'minor_transition_state') || ! Schema::hasColumn('accounts', 'minor_transition_effective_at')) {
            (require base_path('database/migrations/tenant/2026_04_23_110120_add_minor_transition_columns_to_accounts_table.php'))->up();
        }
    }
}
