<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountAuditLog;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorPointsLedger;
use App\Domain\Account\Models\MinorReward;
use App\Domain\Account\Services\MinorChoreService;
use App\Domain\Account\Services\MinorNotificationService;
use App\Domain\Account\Services\MinorRewardService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class MinorAccountPhase4IntegrationTest extends ControllerTestCase
{
    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }

    private MinorChoreService $choreService;

    private MinorRewardService $rewardService;

    private MinorNotificationService $notificationService;

    private User $guardianUser;

    private User $childUser;

    private Account $guardianAccount;

    private Account $minorAccount;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('account_audit_logs')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant/2026_04_15_100003_create_account_audit_logs_table.php',
                '--force' => true,
            ]);
        }

        $this->choreService = app(MinorChoreService::class);
        $this->rewardService = app(MinorRewardService::class);
        $this->notificationService = app(MinorNotificationService::class);

        // Create users
        $this->guardianUser = User::factory()->make();
        $this->guardianUser->setConnection('central');
        $this->guardianUser->save();

        $this->childUser = User::factory()->make();
        $this->childUser->setConnection('central');
        $this->childUser->save();
        $this->tenantId = (string) Str::uuid();

        DB::connection('central')->table('tenants')->insert([
            'id'            => $this->tenantId,
            'name'          => 'Minor Phase 4 Integration Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        // Create accounts
        $this->guardianAccount = Account::factory()->create([
            'user_uuid' => $this->guardianUser->uuid,
            'type'      => 'personal',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'         => $this->childUser->uuid,
            'type'              => 'minor',
            'tier'              => 'grow',
            'parent_account_id' => $this->guardianAccount->id,
        ]);

        // Create account membership
        AccountMembership::create([
            'user_uuid'    => $this->guardianUser->uuid,
            'tenant_id'    => $this->tenantId,
            'account_uuid' => $this->minorAccount->uuid,
            'account_type' => 'minor',
            'role'         => 'guardian',
            'status'       => 'active',
            'joined_at'    => now(),
        ]);
    }

    #[Test]
    public function full_chore_to_redemption_loop_works_end_to_end(): void
    {
        // Step 1: Guardian creates a chore
        $chore = $this->choreService->create(
            $this->guardianAccount,
            $this->minorAccount,
            [
                'title'         => 'Clean the room',
                'description'   => 'Tidy up and organize',
                'payout_points' => 100,
                'due_at'        => Carbon::now()->addDays(7),
            ]
        );

        $this->assertSame('Clean the room', $chore->title);
        $this->assertSame(100, $chore->payout_points);
        $this->assertSame('active', $chore->status);

        // Step 2: Child submits completion
        $completion = $this->choreService->submitCompletion($chore, 'Done!');

        $this->assertSame('pending_review', $completion->status);
        $this->assertDatabaseHas('minor_chore_completions', [
            'chore_id'        => $chore->id,
            'status'          => 'pending_review',
            'submission_note' => 'Done!',
        ]);

        // Step 3: Guardian approves
        $this->choreService->approve($completion, $this->guardianAccount);

        // Verify points were awarded
        $this->assertDatabaseHas('minor_points_ledger', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => 100,
            'source'             => 'chore',
        ]);

        // Get current points balance
        $totalPoints = MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->sum('points');

        $this->assertSame(100, (int) $totalPoints);

        // Step 4: Create a reward
        $reward = MinorReward::create([
            'id'                   => Str::uuid(),
            'name'                 => 'Amazon Gift Card',
            'description'          => '10 USD gift card',
            'points_cost'          => 100,
            'price_points'         => 100,
            'type'                 => 'gift_card',
            'metadata'             => ['amount' => '10.00', 'currency' => 'USD'],
            'stock'                => 5,
            'is_active'            => true,
            'min_permission_level' => 1,
        ]);

        // Step 5: Child redeems the reward
        $redemption = $this->rewardService->redeem($this->minorAccount, $reward);

        $this->assertSame('pending', $redemption->status);
        $this->assertSame($reward->points_cost, $redemption->points_cost);
        $this->assertDatabaseHas('account_audit_logs', [
            'account_uuid' => $this->minorAccount->uuid,
            'action'       => 'minor.reward.redeemed',
            'target_type'  => 'minor_reward_redemption',
            'target_id'    => (string) $redemption->id,
        ]);

        // Step 6: Verify balance is 0 after redemption
        $finalBalance = MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->sum('points');

        $this->assertSame(0, $finalBalance);
    }

    #[Test]
    public function multiple_chore_approvals_accumulate_points_correctly(): void
    {
        // Create first chore
        $chore1 = $this->choreService->create(
            $this->guardianAccount,
            $this->minorAccount,
            [
                'title'         => 'Task 1',
                'payout_points' => 25,
            ]
        );

        // Create second chore
        $chore2 = $this->choreService->create(
            $this->guardianAccount,
            $this->minorAccount,
            [
                'title'         => 'Task 2',
                'payout_points' => 75,
            ]
        );

        // Submit both
        $completion1 = $this->choreService->submitCompletion($chore1);
        $completion2 = $this->choreService->submitCompletion($chore2);

        // Approve both
        $this->choreService->approve($completion1, $this->guardianAccount);
        $this->choreService->approve($completion2, $this->guardianAccount);

        // Verify total balance
        $totalPoints = MinorPointsLedger::query()
            ->where('minor_account_uuid', $this->minorAccount->uuid)
            ->sum('points');

        $this->assertSame(100, (int) $totalPoints);

        // Verify both ledger entries exist
        $this->assertDatabaseHas('minor_points_ledger', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => 25,
            'source'             => 'chore',
        ]);

        $this->assertDatabaseHas('minor_points_ledger', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => 75,
            'source'             => 'chore',
        ]);
    }

    #[Test]
    public function notification_created_when_chore_assigned(): void
    {
        // Create a chore - this triggers the notification
        $chore = $this->choreService->create(
            $this->guardianAccount,
            $this->minorAccount,
            [
                'title'         => 'Homework',
                'payout_points' => 50,
            ]
        );

        // Verify the chore was created
        $this->assertDatabaseHas('minor_chores', [
            'id'     => $chore->id,
            'title'  => 'Homework',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('account_audit_logs', [
            'account_uuid' => $this->minorAccount->uuid,
            'action'       => 'minor.chore.assigned',
            'target_type'  => 'minor_chore',
            'target_id'    => (string) $chore->id,
        ]);
    }

    #[Test]
    public function notification_created_when_chore_approved(): void
    {
        // Create and submit a chore
        $chore = $this->choreService->create(
            $this->guardianAccount,
            $this->minorAccount,
            [
                'title'         => 'Dishes',
                'payout_points' => 30,
            ]
        );

        $completion = $this->choreService->submitCompletion($chore);

        // Approve the chore - this triggers the notification
        $this->choreService->approve($completion, $this->guardianAccount);

        // Verify the points were awarded (proof the approval went through)
        $this->assertDatabaseHas('minor_points_ledger', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => 30,
            'source'             => 'chore',
            'reference_id'       => $completion->id,
        ]);
        $this->assertDatabaseHas('account_audit_logs', [
            'account_uuid' => $this->minorAccount->uuid,
            'action'       => 'minor.chore.approved',
            'target_type'  => 'minor_chore_completion',
            'target_id'    => (string) $completion->id,
        ]);
    }

    #[Test]
    public function reward_redemption_creates_a_durable_audit_trace(): void
    {
        MinorPointsLedger::query()->create([
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => 120,
            'source'             => 'seed',
            'description'        => 'Seed balance for reward audit test',
            'reference_id'       => 'reward-audit-seed',
        ]);

        $reward = MinorReward::create([
            'id'                   => Str::uuid(),
            'name'                 => 'Cinema Ticket',
            'description'          => 'Weekend reward',
            'points_cost'          => 100,
            'price_points'         => 100,
            'type'                 => 'voucher',
            'metadata'             => ['amount' => '1'],
            'stock'                => 3,
            'is_active'            => true,
            'min_permission_level' => 1,
        ]);

        $redemption = $this->rewardService->redeem($this->minorAccount, $reward);

        /** @var AccountAuditLog|null $auditLog */
        $auditLog = AccountAuditLog::query()
            ->where('account_uuid', $this->minorAccount->uuid)
            ->where('action', 'minor.reward.redeemed')
            ->latest('created_at')
            ->first();

        self::assertNotNull($auditLog);
        self::assertSame('minor_reward_redemption', $auditLog->target_type);
        self::assertSame((string) $redemption->id, $auditLog->target_id);
        self::assertSame($this->minorAccount->user_uuid, $auditLog->actor_user_uuid);
        self::assertSame($reward->id, $auditLog->metadata['reward_id'] ?? null);
        self::assertSame($reward->points_cost, $auditLog->metadata['points_cost'] ?? null);
    }
}
