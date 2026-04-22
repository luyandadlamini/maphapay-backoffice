<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorPointsLedger;
use App\Domain\Account\Services\MinorPointsService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorSavingMilestoneTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
    return ['mysql', 'central'];
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
    return false;
    }

    private User $childUser;

    private User $guardianUser;

    private Account $minorAccount;

    private Account $guardianAccount;

    private MinorPointsService $pointsService;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pointsService = app(MinorPointsService::class);
        $this->childUser = User::factory()->create();
        $this->guardianUser = User::factory()->create();

        // Create a tenant row so AccountMembership FK is satisfied
        $this->tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id'            => $this->tenantId,
            'name'          => 'Test Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        $this->guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardianUser->uuid,
            'type'      => 'personal',
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->guardianUser->uuid,
            'account_uuid' => $this->guardianAccount->uuid,
            'tenant_id'    => $this->tenantId,
            'account_type' => 'personal',
            'role'         => 'owner',
            'status'       => 'active',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'         => $this->childUser->uuid,
            'type'              => 'minor',
            'permission_level'  => 3,
            'parent_account_id' => $this->guardianAccount->uuid,
        ]);
        AccountMembership::create([
            'user_uuid'    => $this->guardianUser->uuid,
            'account_uuid' => $this->minorAccount->uuid,
            'tenant_id'    => $this->tenantId,
            'account_type' => 'minor',
            'role'         => 'guardian',
            'status'       => 'active',
        ]);
    }

    #[Test]
    public function saving_milestone_50_points_awarded_when_100_szl_received(): void
    {
        // Simulate 100 SZL total saved in transaction_projections for this minor account
        DB::table('transaction_projections')->insert([
            'uuid'         => Str::uuid(),
            'account_uuid' => $this->minorAccount->uuid,
            'type'         => 'deposit',
            'subtype'      => 'send_money',
            'amount'       => '100.00',
            'asset_code'   => 'SZL',
            'description'  => 'Test deposit',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $totalSaved = (string) DB::table('transaction_projections')
            ->where('account_uuid', $this->minorAccount->uuid)
            ->where('type', 'deposit')
            ->sum('amount');

        $this->pointsService->checkAndAwardSavingMilestones($this->minorAccount, $totalSaved);

        $this->assertDatabaseHas('minor_points_ledger', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => 50,
            'source'             => 'saving_milestone',
            'reference_id'       => '100_szl',
        ]);
    }

    #[Test]
    public function milestone_500_szl_awards_200_points(): void
    {
        $this->pointsService->checkAndAwardSavingMilestones($this->minorAccount, '500.00');
        $balance = $this->pointsService->getBalance($this->minorAccount);
        // Both 100 SZL (50 pts) and 500 SZL (200 pts) milestones awarded
        $this->assertSame(250, $balance);
    }

    #[Test]
    public function milestone_is_not_awarded_twice_for_same_threshold(): void
    {
        $this->pointsService->checkAndAwardSavingMilestones($this->minorAccount, '150.00');
        $this->pointsService->checkAndAwardSavingMilestones($this->minorAccount, '200.00');

        $count = MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->where('source', 'saving_milestone')
            ->where('reference_id', '100_szl')
            ->count();

        $this->assertSame(1, $count);
    }

    #[Test]
    public function updating_permission_level_awards_100_points(): void
    {
        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->putJson("/api/accounts/minor/{$this->minorAccount->uuid}/permission-level", [
            'permission_level' => 4,
        ])->assertOk();

        $this->assertDatabaseHas('minor_points_ledger', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => 100,
            'source'             => 'level_unlock',
        ]);
    }

    #[Test]
    public function level_demotion_does_not_award_points(): void
    {
        // First advance to level 4
        $this->minorAccount->update(['permission_level' => 4]);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);
        $this->putJson("/api/accounts/minor/{$this->minorAccount->uuid}/permission-level", [
            'permission_level' => 3,
        ])->assertOk();

        $this->assertDatabaseMissing('minor_points_ledger', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'source'             => 'level_unlock',
        ]);
    }
}
