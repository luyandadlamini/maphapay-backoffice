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
}
