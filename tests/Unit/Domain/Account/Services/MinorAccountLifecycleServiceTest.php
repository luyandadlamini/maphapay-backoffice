<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorAccountLifecycleException;
use App\Domain\Account\Models\MinorAccountLifecycleTransition;
use App\Domain\User\Models\UserProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorAccountLifecycleServiceTest extends TestCase
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
            'name' => 'Lifecycle unit test tenant',
            'plan' => 'default',
            'team_id' => null,
            'trial_ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'data' => json_encode([]),
        ]);
    }

    #[Test]
    public function it_schedules_and_executes_a_tier_advance_idempotently(): void
    {
        $guardian = User::factory()->create();
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

        AccountMembership::query()->create([
            'tenant_id' => 'tenant-lifecycle',
            'account_uuid' => $account->uuid,
            'user_uuid' => $guardian->uuid,
            'role' => 'guardian',
            'status' => 'active',
            'account_type' => 'minor',
        ]);

        $service = app(\App\Domain\Account\Services\MinorAccountLifecycleService::class);
        $service->evaluateAccount($account, 'test');
        $service->evaluateAccount($account, 'test');

        $account->refresh();
        $transitionCount = MinorAccountLifecycleTransition::query()
            ->where('minor_account_uuid', $account->uuid)
            ->where('transition_type', MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE)
            ->count();

        $this->assertSame('rise', $account->tier);
        $this->assertSame(1, $transitionCount);
    }

    #[Test]
    public function it_opens_an_exception_when_adult_transition_is_blocked(): void
    {
        $guardian = User::factory()->create();
        $child = User::factory()->create(['kyc_status' => 'pending']);
        $account = Account::factory()->create([
            'user_uuid' => $child->uuid,
            'type' => 'minor',
            'tier' => 'rise',
            'permission_level' => 6,
            'frozen' => false,
        ]);

        UserProfile::query()->create([
            'user_id' => $child->id,
            'email' => $child->email,
            'first_name' => 'Teen',
            'status' => 'active',
            'date_of_birth' => now()->subYears(18)->toDateString(),
            'is_verified' => false,
        ]);

        AccountMembership::query()->create([
            'tenant_id' => 'tenant-lifecycle',
            'account_uuid' => $account->uuid,
            'user_uuid' => $guardian->uuid,
            'role' => 'guardian',
            'status' => 'active',
            'account_type' => 'minor',
        ]);

        app(\App\Domain\Account\Services\MinorAccountLifecycleService::class)->evaluateAccount($account, 'test');

        $account->refresh();
        $exception = MinorAccountLifecycleException::query()
            ->where('minor_account_uuid', $account->uuid)
            ->where('reason_code', \App\Domain\Account\Services\MinorAccountLifecyclePolicy::REASON_ADULT_KYC_NOT_READY)
            ->first();

        $this->assertNotNull($exception);
        $this->assertTrue((bool) $account->frozen);
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
