<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorPointsLedger;
use App\Domain\Account\Models\MinorReward;
use App\Domain\Account\Models\MinorRewardRedemption;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\CreatesApplication;

class MinorRedemptionOrdersControllerTest extends BaseTestCase
{
    use CreatesApplication;

    private string $tenantId;

    private User $guardianUser;

    private User $childUser;

    private Account $guardianAccount;

    private Account $minorAccount;

    private MinorReward $smallReward;

    private MinorReward $largeReward;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        if (! Schema::hasTable('minor_points_ledger')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant/2026_04_18_100000_create_minor_points_ledger_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_reward_redemptions') || ! Schema::hasColumn('minor_reward_redemptions', 'minor_account_uuid')) {
            Schema::dropIfExists('minor_reward_redemptions');

            Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant/2026_04_18_100002_create_minor_reward_redemptions_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_rewards')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant/2026_04_20_099999_create_minor_rewards_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasColumn('minor_rewards', 'is_featured')) {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/tenant/2026_04_20_100000_add_phase_8_columns_to_minor_rewards_table.php',
                '--force' => true,
            ]);
        }

        config()->set('minor_accounts.redemptions.approval_threshold', 250);

        $this->tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id' => $this->tenantId,
            'name' => 'Minor Phase 8 Redemption Tenant',
            'plan' => 'default',
            'team_id' => null,
            'trial_ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'data' => json_encode([]),
        ]);

        $this->guardianUser = User::factory()->create();
        $this->childUser = User::factory()->create();

        $this->guardianAccount = $this->createOwnedPersonalAccount($this->guardianUser);
        $this->minorAccount = Account::factory()->create([
            'user_uuid' => $this->childUser->uuid,
            'type' => 'minor',
            'permission_level' => 3,
            'parent_account_id' => $this->guardianAccount->uuid,
        ]);

        $this->createMinorMembership($this->guardianUser, $this->minorAccount, 'guardian');

        MinorPointsLedger::query()->create([
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points' => 500,
            'source' => 'seed',
            'description' => 'Seed points',
            'reference_id' => 'seed-points',
        ]);

        $this->smallReward = MinorReward::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Airtime 50',
            'description' => 'Immediate airtime reward',
            'points_cost' => 100,
            'price_points' => 100,
            'type' => 'airtime',
            'metadata' => ['provider' => 'MTN'],
            'stock' => 5,
            'is_active' => true,
            'is_featured' => false,
            'min_permission_level' => 1,
        ]);

        $this->largeReward = MinorReward::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Tablet Voucher',
            'description' => 'Needs parent approval',
            'points_cost' => 300,
            'price_points' => 300,
            'type' => 'voucher',
            'metadata' => ['partner_name' => 'Tech Store'],
            'stock' => 2,
            'is_active' => true,
            'is_featured' => true,
            'min_permission_level' => 1,
        ]);
    }

    #[Test]
    public function submit_endpoint_rejects_quantities_the_child_cannot_afford(): void
    {
        Sanctum::actingAs($this->childUser, ['read', 'write', 'delete']);

        $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/redemptions/submit", [
            'reward_id' => $this->smallReward->id,
            'quantity' => 6,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    }

    #[Test]
    public function submit_endpoint_deducts_points_immediately_when_under_the_approval_threshold(): void
    {
        Sanctum::actingAs($this->childUser, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/redemptions/submit", [
            'reward_id' => $this->smallReward->id,
            'quantity' => 2,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.points_cost', 200)
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.requires_approval', false);

        $redemptionId = (string) $response->json('data.id');

        $this->assertSame(300, (int) MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->sum('points'));

        $this->assertSame(1, MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->where('source', 'redemption')
            ->where('reference_id', $redemptionId)
            ->count());
    }

    #[Test]
    public function submit_and_approval_endpoints_are_transactional_and_idempotent_for_parent_approval_orders(): void
    {
        Sanctum::actingAs($this->childUser, ['read', 'write', 'delete']);

        $submitResponse = $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/redemptions/submit", [
            'reward_id' => $this->largeReward->id,
            'quantity' => 1,
        ]);

        $submitResponse->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_approval')
            ->assertJsonPath('data.points_cost', 300)
            ->assertJsonPath('data.requires_approval', true);

        $redemptionId = (string) $submitResponse->json('data.id');

        $this->assertSame(500, (int) MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->sum('points'));

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $firstApproval = $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/redemptions/{$redemptionId}/approve");
        $secondApproval = $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/redemptions/{$redemptionId}/approve");

        $firstApproval->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $secondApproval->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(200, (int) MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->sum('points'));

        $this->assertSame(1, MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->where('source', 'redemption')
            ->where('reference_id', $redemptionId)
            ->count());

        $this->assertSame('approved', MinorRewardRedemption::query()->findOrFail($redemptionId)->status);
    }

    #[Test]
    public function decline_endpoint_is_idempotent_and_does_not_refund_when_no_deduction_exists(): void
    {
        Sanctum::actingAs($this->childUser, ['read', 'write', 'delete']);

        $submitResponse = $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/redemptions/submit", [
            'reward_id' => $this->largeReward->id,
            'quantity' => 1,
        ]);

        $submitResponse->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_approval');

        $redemptionId = (string) $submitResponse->json('data.id');

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $firstDecline = $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/redemptions/{$redemptionId}/decline");
        $secondDecline = $this->postJson("/api/accounts/minor/{$this->minorAccount->uuid}/redemptions/{$redemptionId}/decline");

        $firstDecline->assertOk()
            ->assertJsonPath('data.status', 'declined');

        $secondDecline->assertOk()
            ->assertJsonPath('data.status', 'declined');

        $this->assertSame(500, (int) MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->sum('points'));

        $this->assertSame(0, MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->where('source', 'redemption_refund')
            ->where('reference_id', $redemptionId)
            ->count());
    }

    #[Test]
    public function redemptions_index_returns_the_stabilized_phase_8_order_contract(): void
    {
        $redemption = MinorRewardRedemption::query()->create([
            'id' => (string) Str::uuid(),
            'minor_account_uuid' => $this->minorAccount->uuid,
            'minor_reward_id' => $this->largeReward->id,
            'points_cost' => 300,
            'status' => 'awaiting_approval',
        ]);

        Sanctum::actingAs($this->guardianUser, ['read', 'write', 'delete']);

        $this->getJson("/api/accounts/minor/{$this->minorAccount->uuid}/redemptions")
            ->assertOk()
            ->assertJsonPath('data.minor_account_uuid', $this->minorAccount->uuid)
            ->assertJsonPath('data.redemptions.0.id', $redemption->id)
            ->assertJsonPath('data.redemptions.0.reward_id', $this->largeReward->id)
            ->assertJsonPath('data.redemptions.0.status', 'awaiting_approval')
            ->assertJsonPath('data.redemptions.0.requires_approval', true)
            ->assertJsonPath('data.redemptions.0.quantity', 1);
    }

    private function createOwnedPersonalAccount(User $user): Account
    {
        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'type' => 'personal',
        ]);

        AccountMembership::query()->create([
            'user_uuid' => $user->uuid,
            'tenant_id' => $this->tenantId,
            'account_uuid' => $account->uuid,
            'account_type' => 'personal',
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $account;
    }

    private function createMinorMembership(User $user, Account $minorAccount, string $role): void
    {
        AccountMembership::query()->create([
            'user_uuid' => $user->uuid,
            'tenant_id' => $this->tenantId,
            'account_uuid' => $minorAccount->uuid,
            'account_type' => 'minor',
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }
}
