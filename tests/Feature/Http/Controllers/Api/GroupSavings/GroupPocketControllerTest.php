<?php
declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\GroupSavings;

use App\Models\GroupPocket;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GroupPocketControllerTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    /** @param array<int, User> $members */
    private function makeGroupThread(User $admin, array $members = []): Thread
    {
        $thread = Thread::create([
            'type'       => 'group',
            'name'       => 'Test Group',
            'created_by' => $admin->id,
        ]);

        ThreadParticipant::create([
            'thread_id' => $thread->id,
            'user_id'   => $admin->id,
            'role'      => 'admin',
            'joined_at' => now(),
        ]);

        foreach ($members as $member) {
            ThreadParticipant::create([
                'thread_id' => $thread->id,
                'user_id'   => $member->id,
                'role'      => 'member',
                'joined_at' => now(),
            ]);
        }

        return $thread;
    }

    #[Test]
    public function model_can_be_created_and_retrieved(): void
    {
        $admin = User::factory()->create();
        $thread = $this->makeGroupThread($admin);

        $pocket = GroupPocket::create([
            'thread_id'     => $thread->id,
            'created_by'    => $admin->id,
            'name'          => 'Holiday Fund',
            'category'      => 'travel',
            'color'         => '#6366F1',
            'target_amount' => 5000.00,
        ]);

        $this->assertDatabaseHas('group_pockets', [
            'id'             => $pocket->id,
            'name'           => 'Holiday Fund',
            'status'         => 'active',
            'is_completed'   => false,
            'current_amount' => '0.00',
        ]);
    }

    #[Test]
    public function add_funds_marks_pocket_completed_when_target_reached(): void
    {
        $admin  = User::factory()->create();
        $thread = $this->makeGroupThread($admin);

        $pocket = GroupPocket::create([
            'thread_id'     => $thread->id,
            'created_by'    => $admin->id,
            'name'          => 'Fund',
            'category'      => 'general',
            'color'         => '#fff',
            'target_amount' => 500.00,
        ]);

        $pocket->addFunds('500.00');

        $this->assertTrue($pocket->is_completed);
        $this->assertSame(GroupPocket::STATUS_COMPLETED, $pocket->status);
        $this->assertSame('500.00', (string) $pocket->current_amount);
    }

    #[Test]
    public function deduct_funds_resets_completed_status(): void
    {
        $admin  = User::factory()->create();
        $thread = $this->makeGroupThread($admin);

        $pocket = GroupPocket::create([
            'thread_id'      => $thread->id,
            'created_by'     => $admin->id,
            'name'           => 'Fund',
            'category'       => 'general',
            'color'          => '#fff',
            'target_amount'  => 500.00,
            'current_amount' => 500.00,
            'is_completed'   => true,
            'status'         => GroupPocket::STATUS_COMPLETED,
        ]);

        $pocket->deductFunds('100.00');

        $this->assertFalse($pocket->is_completed);
        $this->assertSame(GroupPocket::STATUS_ACTIVE, $pocket->status);
    }

    #[Test]
    public function would_exceed_regulatory_max_returns_true_when_over_limit(): void
    {
        $admin  = User::factory()->create();
        $thread = $this->makeGroupThread($admin);

        $pocket = GroupPocket::create([
            'thread_id'      => $thread->id,
            'created_by'     => $admin->id,
            'name'           => 'Fund',
            'category'       => 'general',
            'color'          => '#fff',
            'target_amount'  => 200000.00,
            'current_amount' => 99999.00,
        ]);

        $this->assertTrue($pocket->wouldExceedRegulatoryMax('2.00'));
        $this->assertFalse($pocket->wouldExceedRegulatoryMax('1.00'));
    }

    #[Test]
    public function thread_has_group_pockets_relation(): void
    {
        $admin = User::factory()->create();
        $thread = $this->makeGroupThread($admin);

        GroupPocket::create([
            'thread_id'     => $thread->id,
            'created_by'    => $admin->id,
            'name'          => 'Trip Fund',
            'category'      => 'travel',
            'color'         => '#6366F1',
            'target_amount' => 1000.00,
        ]);

        $this->assertCount(1, $thread->groupPockets);
    }

    #[Test]
    public function member_can_create_a_group_pocket(): void
    {
        $admin  = User::factory()->create();
        $member = User::factory()->create();
        $thread = $this->makeGroupThread($admin, [$member]);

        Sanctum::actingAs($member, ['read', 'write']);

        $response = $this->postJson('/api/savings/group-pockets', [
            'thread_id'     => $thread->id,
            'name'          => 'Holiday Fund',
            'category'      => 'travel',
            'color'         => '#6366F1',
            'target_amount' => 5000,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.pocket.name', 'Holiday Fund')
            ->assertJsonPath('data.pocket.status', 'active');

        $this->assertDatabaseHas('group_pockets', ['name' => 'Holiday Fund', 'thread_id' => $thread->id]);
    }

    #[Test]
    public function non_member_cannot_create_a_group_pocket(): void
    {
        $admin    = User::factory()->create();
        $outsider = User::factory()->create();
        $thread   = $this->makeGroupThread($admin);

        Sanctum::actingAs($outsider, ['read', 'write']);

        $this->postJson('/api/savings/group-pockets', [
            'thread_id'     => $thread->id,
            'name'          => 'Secret Fund',
            'category'      => 'general',
            'color'         => '#000000',
            'target_amount' => 1000,
        ])->assertStatus(403);
    }

    #[Test]
    public function member_can_list_pockets_for_their_thread(): void
    {
        $admin  = User::factory()->create();
        $member = User::factory()->create();
        $thread = $this->makeGroupThread($admin, [$member]);

        GroupPocket::create([
            'thread_id' => $thread->id, 'created_by' => $admin->id,
            'name' => 'Fund A', 'category' => 'general', 'color' => '#fff', 'target_amount' => 1000,
        ]);

        Sanctum::actingAs($member, ['read']);

        $this->getJson("/api/savings/group-pockets/thread/{$thread->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.pockets');
    }

    #[Test]
    public function admin_can_update_pocket_name(): void
    {
        $admin  = User::factory()->create();
        $thread = $this->makeGroupThread($admin);

        $pocket = GroupPocket::create([
            'thread_id' => $thread->id, 'created_by' => $admin->id,
            'name' => 'Old Name', 'category' => 'general', 'color' => '#fff', 'target_amount' => 1000,
        ]);

        Sanctum::actingAs($admin, ['read', 'write']);

        $this->patchJson("/api/savings/group-pockets/{$pocket->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.pocket.name', 'New Name');
    }

    #[Test]
    public function non_admin_cannot_update_pocket(): void
    {
        $admin  = User::factory()->create();
        $member = User::factory()->create();
        $thread = $this->makeGroupThread($admin, [$member]);

        $pocket = GroupPocket::create([
            'thread_id' => $thread->id, 'created_by' => $admin->id,
            'name' => 'Fund', 'category' => 'general', 'color' => '#fff', 'target_amount' => 1000,
        ]);

        Sanctum::actingAs($member, ['read', 'write']);

        $this->patchJson("/api/savings/group-pockets/{$pocket->id}", ['name' => 'Hacked'])
            ->assertStatus(403);
    }

    #[Test]
    public function authenticated_user_can_list_all_their_pockets(): void
    {
        $user    = User::factory()->create();
        $thread1 = $this->makeGroupThread($user);
        $thread2 = $this->makeGroupThread($user);

        GroupPocket::create([
            'thread_id' => $thread1->id, 'created_by' => $user->id,
            'name' => 'Fund 1', 'category' => 'general', 'color' => '#fff', 'target_amount' => 1000,
        ]);
        GroupPocket::create([
            'thread_id' => $thread2->id, 'created_by' => $user->id,
            'name' => 'Fund 2', 'category' => 'travel', 'color' => '#000', 'target_amount' => 2000,
        ]);

        Sanctum::actingAs($user, ['read']);

        $this->getJson('/api/savings/group-pockets')
            ->assertOk()
            ->assertJsonCount(2, 'data.pockets');
    }

    #[Test]
    public function admin_can_close_pocket(): void
    {
        $admin  = User::factory()->create();
        $thread = $this->makeGroupThread($admin);
        $pocket = GroupPocket::create([
            'thread_id' => $thread->id, 'created_by' => $admin->id,
            'name' => 'Fund', 'category' => 'general', 'color' => '#fff', 'target_amount' => 1000,
        ]);

        Sanctum::actingAs($admin, ['read', 'write', 'delete']);

        $this->deleteJson("/api/savings/group-pockets/{$pocket->id}")
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('group_pockets', [
            'id'     => $pocket->id,
            'status' => GroupPocket::STATUS_CLOSED,
        ]);
    }

    #[Test]
    public function non_admin_cannot_close_pocket(): void
    {
        $admin  = User::factory()->create();
        $member = User::factory()->create();
        $thread = $this->makeGroupThread($admin, [$member]);
        $pocket = GroupPocket::create([
            'thread_id' => $thread->id, 'created_by' => $admin->id,
            'name' => 'Fund', 'category' => 'general', 'color' => '#fff', 'target_amount' => 1000,
        ]);

        Sanctum::actingAs($member, ['read', 'write', 'delete']);

        $this->deleteJson("/api/savings/group-pockets/{$pocket->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('group_pockets', [
            'id'     => $pocket->id,
            'status' => GroupPocket::STATUS_ACTIVE,
        ]);
    }
}
