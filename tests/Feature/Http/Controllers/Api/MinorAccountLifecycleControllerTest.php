<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorAccountLifecycleException;
use App\Domain\User\Models\UserProfile;
use App\Http\Middleware\ResolveAccountContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MinorAccountLifecycleControllerTest extends TestCase
{
    private string $tenantId;

    private User $guardianUser;

    private User $childUser;

    private Account $guardianAccount;

    private Account $minorAccount;

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ResolveAccountContext::class);

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

        $this->tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id'            => $this->tenantId,
            'name'          => 'Minor Lifecycle API Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        $this->guardianUser = User::factory()->create();
        $this->childUser = User::factory()->create(['kyc_status' => 'pending']);
        $this->guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardianUser->uuid,
            'type'      => 'personal',
        ]);
        $this->minorAccount = Account::factory()->create([
            'user_uuid'         => $this->childUser->uuid,
            'type'              => 'minor',
            'tier'              => 'rise',
            'permission_level'  => 6,
            'parent_account_id' => $this->guardianAccount->uuid,
        ]);

        AccountMembership::query()->create([
            'tenant_id'    => $this->tenantId,
            'account_uuid' => $this->minorAccount->uuid,
            'user_uuid'    => $this->guardianUser->uuid,
            'role'         => 'guardian',
            'status'       => 'active',
            'account_type' => 'minor',
        ]);

        UserProfile::query()->create([
            'user_id'       => $this->childUser->id,
            'email'         => $this->childUser->email,
            'first_name'    => 'Teen',
            'status'        => 'active',
            'date_of_birth' => now()->subYears(18)->toDateString(),
            'is_verified'   => false,
        ]);
    }

    #[Test]
    public function guardian_can_read_lifecycle_snapshot(): void
    {
        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/lifecycle")
            ->assertOk()
            ->assertJsonPath('data.minor_account_uuid', $this->minorAccount->uuid)
            ->assertJsonStructure(['data' => ['pending_milestones', 'blockers', 'next_actions']]);
    }

    #[Test]
    public function guardian_can_rerun_lifecycle_evaluation(): void
    {
        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/lifecycle/review-actions", [
            'action' => 'rerun_evaluation',
        ])->assertStatus(202);
    }

    #[Test]
    public function operator_can_acknowledge_and_resolve_lifecycle_exceptions(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations-l2');
        Permission::firstOrCreate(['name' => 'view-transactions', 'guard_name' => 'web']);
        $operator->givePermissionTo('view-transactions');
        Sanctum::actingAs($operator, ['read', 'write', 'delete']);

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/lifecycle/review-actions", [
            'action' => 'rerun_evaluation',
        ]);

        $exception = MinorAccountLifecycleException::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->firstOrFail();

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/lifecycle/review-actions", [
            'action'         => 'acknowledge_exception',
            'exception_uuid' => $exception->id,
            'note'           => 'Investigating the lifecycle blocker.',
        ])->assertStatus(202);

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/lifecycle/review-actions", [
            'action'         => 'resolve_exception',
            'exception_uuid' => $exception->id,
            'note'           => 'Manual resolution recorded.',
        ])->assertStatus(202);

        $this->assertSame(
            MinorAccountLifecycleException::STATUS_RESOLVED,
            $exception->fresh()?->status
        );
    }
}
