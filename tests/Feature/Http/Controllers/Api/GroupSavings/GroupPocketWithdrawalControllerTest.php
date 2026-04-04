<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\GroupSavings;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Models\GroupPocket;
use App\Models\GroupPocketWithdrawalRequest;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GroupPocketWithdrawalControllerTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['banking.default_currency' => 'SZL']);

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );
    }

    private function makeGroupThread(User $admin, array $members = []): Thread
    {
        $thread = Thread::create([
            'type' => 'group', 'name' => 'Test Group', 'created_by' => $admin->id,
        ]);

        ThreadParticipant::create([
            'thread_id' => $thread->id, 'user_id' => $admin->id,
            'role' => 'admin', 'joined_at' => now(),
        ]);

        foreach ($members as $member) {
            ThreadParticipant::create([
                'thread_id' => $thread->id, 'user_id' => $member->id,
                'role' => 'member', 'joined_at' => now(),
            ]);
        }

        return $thread;
    }

    private function makeFundedPocket(Thread $thread, User $creator, float $balance = 1000): GroupPocket
    {
        return GroupPocket::create([
            'thread_id'      => $thread->id,
            'created_by'     => $creator->id,
            'name'           => 'Funded Pocket',
            'category'       => 'general',
            'color'          => '#6366F1',
            'target_amount'  => 5000,
            'current_amount' => $balance,
        ]);
    }

    private function fundUser(User $user, float $amountMajor): void
    {
        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        AccountBalance::factory()
            ->forAccount($account)
            ->forAsset('SZL')
            ->withBalance($asset->toSmallestUnit($amountMajor))
            ->create();
    }

    #[Test]
    public function member_can_request_a_withdrawal(): void
    {
        $admin  = User::factory()->create();
        $member = User::factory()->create();
        $thread = $this->makeGroupThread($admin, [$member]);
        $pocket = $this->makeFundedPocket($thread, $admin);

        Sanctum::actingAs($member, ['read', 'write']);

        $this->postJson("/api/savings/group-pockets/{$pocket->id}/withdraw-request", [
            'amount' => 200,
            'note'   => 'Need for transport',
        ])->assertStatus(201)
          ->assertJsonPath('data.request.status', 'pending');

        $this->assertDatabaseHas('group_pocket_withdrawal_requests', [
            'group_pocket_id' => $pocket->id,
            'requested_by'    => $member->id,
            'status'          => 'pending',
        ]);
    }

    #[Test]
    public function withdrawal_request_is_rejected_when_pocket_is_locked(): void
    {
        $admin  = User::factory()->create();
        $member = User::factory()->create();
        $thread = $this->makeGroupThread($admin, [$member]);
        $pocket = $this->makeFundedPocket($thread, $admin);
        $pocket->update(['is_locked' => true]);

        Sanctum::actingAs($member, ['read', 'write']);

        $this->postJson("/api/savings/group-pockets/{$pocket->id}/withdraw-request", ['amount' => 100])
            ->assertStatus(422)
            ->assertJsonPath('message.0', 'Pocket is locked — withdrawals are disabled');
    }

    #[Test]
    public function admin_can_approve_withdrawal_request(): void
    {
        $admin  = User::factory()->create();
        $member = User::factory()->create();
        $thread = $this->makeGroupThread($admin, [$member]);
        $pocket = $this->makeFundedPocket($thread, $admin, 1000);

        // Fund the member's wallet so the credit can be applied
        $this->fundUser($member, 0.00);

        $withdrawalRequest = GroupPocketWithdrawalRequest::create([
            'group_pocket_id' => $pocket->id,
            'requested_by'    => $member->id,
            'amount'          => 300,
            'status'          => 'pending',
        ]);

        Sanctum::actingAs($admin, ['read', 'write']);

        $this->postJson("/api/savings/group-pockets/{$pocket->id}/withdraw-request/{$withdrawalRequest->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.request.status', 'approved');

        $this->assertDatabaseHas('group_pockets', [
            'id' => $pocket->id, 'current_amount' => '700.00',
        ]);
    }

    #[Test]
    public function non_admin_cannot_approve_withdrawal(): void
    {
        $admin  = User::factory()->create();
        $member = User::factory()->create();
        $thread = $this->makeGroupThread($admin, [$member]);
        $pocket = $this->makeFundedPocket($thread, $admin);

        $withdrawalRequest = GroupPocketWithdrawalRequest::create([
            'group_pocket_id' => $pocket->id,
            'requested_by'    => $member->id,
            'amount'          => 100,
            'status'          => 'pending',
        ]);

        Sanctum::actingAs($member, ['read', 'write']);

        $this->postJson("/api/savings/group-pockets/{$pocket->id}/withdraw-request/{$withdrawalRequest->id}/approve")
            ->assertStatus(403);
    }

    #[Test]
    public function admin_can_reject_withdrawal_request(): void
    {
        $admin  = User::factory()->create();
        $member = User::factory()->create();
        $thread = $this->makeGroupThread($admin, [$member]);
        $pocket = $this->makeFundedPocket($thread, $admin);

        $withdrawalRequest = GroupPocketWithdrawalRequest::create([
            'group_pocket_id' => $pocket->id,
            'requested_by'    => $member->id,
            'amount'          => 100,
            'status'          => 'pending',
        ]);

        Sanctum::actingAs($admin, ['read', 'write']);

        $this->postJson("/api/savings/group-pockets/{$pocket->id}/withdraw-request/{$withdrawalRequest->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.request.status', 'rejected');

        $this->assertDatabaseHas('group_pockets', [
            'id' => $pocket->id, 'current_amount' => (string) $pocket->current_amount,
        ]);
    }
}
