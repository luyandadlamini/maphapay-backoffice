<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\SocialMoney;

use App\Domain\SocialMoney\Events\Broadcast\GroupUpdated;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GroupControllerTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSocialThreadTables();
        $this->ensureSocialFriendshipTable();
    }

    private function makeFriends(int $a, int $b): void
    {
        DB::table('friendships')->insert([
            ['user_id' => $a, 'friend_id' => $b, 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $b, 'friend_id' => $a, 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    #[Test]
    public function create_group_with_friends(): void
    {
        Event::fake([GroupUpdated::class]);
        $creator = User::factory()->create(['name' => 'Alice']);
        $friend1 = User::factory()->create(['name' => 'Bob']);
        $friend2 = User::factory()->create(['name' => 'Carol']);
        $this->makeFriends($creator->id, $friend1->id);
        $this->makeFriends($creator->id, $friend2->id);

        Sanctum::actingAs($creator, ['read', 'write', 'delete']);

        $response = $this->postJson('/api/social-money/groups', [
            'name'      => 'Roommates',
            'memberIds' => [$friend1->id, $friend2->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.thread.type', 'group')
            ->assertJsonPath('data.thread.name', 'Roommates');

        $threadId = $response->json('data.thread.id');
        $this->assertSame(
            3,
            ThreadParticipant::query()->where('thread_id', $threadId)->count()
        );
        $this->assertDatabaseHas('thread_participants', [
            'thread_id' => $threadId, 'user_id' => $creator->id, 'role' => 'admin',
        ]);
    }

    #[Test]
    public function create_group_rejects_non_friends(): void
    {
        $creator = User::factory()->create();
        $stranger = User::factory()->create();

        Sanctum::actingAs($creator, ['read', 'write', 'delete']);

        $this->postJson('/api/social-money/groups', [
            'name' => 'Test', 'memberIds' => [$stranger->id],
        ])->assertUnprocessable();
    }

    #[Test]
    public function create_group_rejects_exceeding_max_participants(): void
    {
        $creator = User::factory()->create();
        $friends = User::factory()->count(16)->create();
        foreach ($friends as $f) {
            $this->makeFriends($creator->id, $f->id);
        }

        Sanctum::actingAs($creator, ['read', 'write', 'delete']);

        $this->postJson('/api/social-money/groups', [
            'name' => 'Too Big', 'memberIds' => $friends->pluck('id')->all(),
        ])->assertUnprocessable();
    }

    #[Test]
    public function admin_can_add_members(): void
    {
        Event::fake([GroupUpdated::class]);
        $admin = User::factory()->create(['name' => 'Alice']);
        $member = User::factory()->create(['name' => 'Bob']);
        $newFriend = User::factory()->create(['name' => 'Carol']);
        $this->makeFriends($admin->id, $member->id);
        $this->makeFriends($admin->id, $newFriend->id);

        $thread = Thread::create(['type' => 'group', 'name' => 'Test', 'created_by' => $admin->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);

        Sanctum::actingAs($admin, ['read', 'write', 'delete']);

        $this->postJson("/api/social-money/groups/{$thread->id}/members", [
            'userIds' => [$newFriend->id],
        ])->assertOk();

        $this->assertDatabaseHas('thread_participants', [
            'thread_id' => $thread->id, 'user_id' => $newFriend->id, 'role' => 'member',
        ]);
    }

    #[Test]
    public function non_admin_cannot_add_members(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $newFriend = User::factory()->create();
        $this->makeFriends($member->id, $newFriend->id);

        $thread = Thread::create(['type' => 'group', 'name' => 'Test', 'created_by' => $admin->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);

        Sanctum::actingAs($member, ['read', 'write', 'delete']);

        $this->postJson("/api/social-money/groups/{$thread->id}/members", [
            'userIds' => [$newFriend->id],
        ])->assertForbidden();
    }

    #[Test]
    public function admin_can_remove_member(): void
    {
        Event::fake([GroupUpdated::class]);
        $admin = User::factory()->create(['name' => 'Alice']);
        $member = User::factory()->create(['name' => 'Bob']);

        $thread = Thread::create(['type' => 'group', 'name' => 'Test', 'created_by' => $admin->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);

        Sanctum::actingAs($admin, ['read', 'write', 'delete']);

        $this->deleteJson("/api/social-money/groups/{$thread->id}/members/{$member->id}")
            ->assertOk();

        $this->assertDatabaseHas('thread_participants', [
            'thread_id' => $thread->id, 'user_id' => $member->id,
        ]);
        $this->assertNotNull(
            ThreadParticipant::where('thread_id', $thread->id)->where('user_id', $member->id)->value('left_at')
        );
    }

    #[Test]
    public function member_can_leave_group(): void
    {
        Event::fake([GroupUpdated::class]);
        $admin = User::factory()->create();
        $member = User::factory()->create(['name' => 'Bob']);

        $thread = Thread::create(['type' => 'group', 'name' => 'Test', 'created_by' => $admin->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);

        Sanctum::actingAs($member, ['read', 'write', 'delete']);

        $this->postJson("/api/social-money/groups/{$thread->id}/leave")
            ->assertOk();

        $this->assertNotNull(
            ThreadParticipant::where('thread_id', $thread->id)->where('user_id', $member->id)->value('left_at')
        );
    }

    #[Test]
    public function last_admin_cannot_leave_if_other_members_exist(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();

        $thread = Thread::create(['type' => 'group', 'name' => 'Test', 'created_by' => $admin->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);

        Sanctum::actingAs($admin, ['read', 'write', 'delete']);

        $this->postJson("/api/social-money/groups/{$thread->id}/leave")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Promote another member to admin before leaving');
    }

    #[Test]
    public function last_member_leaving_deletes_group(): void
    {
        $user = User::factory()->create();

        $thread = Thread::create(['type' => 'group', 'name' => 'Solo', 'created_by' => $user->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $user->id, 'role' => 'admin', 'joined_at' => now()]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $this->postJson("/api/social-money/groups/{$thread->id}/leave")
            ->assertOk();

        $this->assertDatabaseMissing('threads', ['id' => $thread->id]);
    }

    #[Test]
    public function admin_can_change_member_role(): void
    {
        Event::fake([GroupUpdated::class]);
        $admin = User::factory()->create(['name' => 'Alice']);
        $member = User::factory()->create(['name' => 'Bob']);

        $thread = Thread::create(['type' => 'group', 'name' => 'Test', 'created_by' => $admin->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);

        Sanctum::actingAs($admin, ['read', 'write', 'delete']);

        $this->postJson("/api/social-money/groups/{$thread->id}/members/{$member->id}/role", [
            'role' => 'admin',
        ])->assertOk();

        $this->assertDatabaseHas('thread_participants', [
            'thread_id' => $thread->id, 'user_id' => $member->id, 'role' => 'admin',
        ]);
    }

    #[Test]
    public function last_admin_cannot_demote_self(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();

        $thread = Thread::create(['type' => 'group', 'name' => 'Test', 'created_by' => $admin->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $member->id, 'role' => 'member', 'joined_at' => now()]);

        Sanctum::actingAs($admin, ['read', 'write', 'delete']);

        $this->postJson("/api/social-money/groups/{$thread->id}/members/{$admin->id}/role", [
            'role' => 'member',
        ])->assertUnprocessable();
    }

    #[Test]
    public function admin_can_rename_group(): void
    {
        Event::fake([GroupUpdated::class]);
        $admin = User::factory()->create(['name' => 'Alice']);

        $thread = Thread::create(['type' => 'group', 'name' => 'Old Name', 'created_by' => $admin->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);

        Sanctum::actingAs($admin, ['read', 'write', 'delete']);

        $this->patchJson("/api/social-money/groups/{$thread->id}", ['name' => 'New Name'])
            ->assertOk();

        $this->assertDatabaseHas('threads', ['id' => $thread->id, 'name' => 'New Name']);
    }

    #[Test]
    public function admin_can_delete_group(): void
    {
        Event::fake([GroupUpdated::class]);
        $admin = User::factory()->create();

        $thread = Thread::create(['type' => 'group', 'name' => 'Delete Me', 'created_by' => $admin->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now()]);

        Sanctum::actingAs($admin, ['read', 'write', 'delete']);

        $this->deleteJson("/api/social-money/groups/{$thread->id}")
            ->assertOk();

        $this->assertDatabaseMissing('threads', ['id' => $thread->id]);
    }

    private function ensureSocialFriendshipTable(): void
    {
        if (Schema::hasTable('friendships')) {
            return;
        }

        Schema::create('friendships', function ($table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('friend_id');
            $table->string('status')->default('accepted');
            $table->timestamps();
        });
    }

    private function ensureSocialThreadTables(): void
    {
        if (Schema::hasTable('threads')) {
            return;
        }

        foreach ([
            '2026_04_04_000001_create_threads_table.php',
            '2026_04_04_000002_create_thread_participants_table.php',
            '2026_04_04_000003_create_messages_table.php',
            '2026_04_04_000004_create_message_reads_table.php',
            '2026_04_04_000005_create_bill_splits_table.php',
            '2026_04_04_000006_create_bill_split_participants_table.php',
        ] as $migrationFile) {
            $migration = require base_path("database/migrations/{$migrationFile}");
            $migration->up();
        }
    }
}
