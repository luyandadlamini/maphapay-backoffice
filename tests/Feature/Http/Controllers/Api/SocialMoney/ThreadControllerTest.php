<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\SocialMoney;

use App\Models\Message;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThreadControllerTest extends TestCase
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

    #[Test]
    public function list_threads_returns_direct_and_group_sorted_by_latest_message(): void
    {
        $user = User::factory()->create(['name' => 'Alice']);
        $friend = User::factory()->create(['name' => 'Bob']);

        $direct = Thread::create(['type' => 'direct', 'created_by' => $user->id]);
        ThreadParticipant::create(['thread_id' => $direct->id, 'user_id' => $user->id, 'role' => 'member', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $direct->id, 'user_id' => $friend->id, 'role' => 'member', 'joined_at' => now()]);
        Message::create(['thread_id' => $direct->id, 'sender_id' => $friend->id, 'type' => 'text', 'text' => 'Hello', 'created_at' => now()->subMinutes(5)]);

        $group = Thread::create(['type' => 'group', 'name' => 'Roommates', 'created_by' => $user->id]);
        ThreadParticipant::create(['thread_id' => $group->id, 'user_id' => $user->id, 'role' => 'admin', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $group->id, 'user_id' => $friend->id, 'role' => 'member', 'joined_at' => now()]);
        Message::create(['thread_id' => $group->id, 'sender_id' => $user->id, 'type' => 'text', 'text' => 'Rent is due', 'created_at' => now()]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->getJson('/api/social-money/threads');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data.threads');

        $threads = $response->json('data.threads');
        $this->assertEquals('group', $threads[0]['type']);
        $this->assertEquals('Roommates', $threads[0]['name']);
        $this->assertEquals('direct', $threads[1]['type']);
        $this->assertEquals('Bob', $threads[1]['friendName']);
    }

    #[Test]
    public function list_threads_excludes_threads_user_has_left(): void
    {
        $user = User::factory()->create();
        $thread = Thread::create(['type' => 'group', 'name' => 'Old Group', 'created_by' => $user->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $user->id, 'role' => 'member', 'joined_at' => now(), 'left_at' => now()]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $this->getJson('/api/social-money/threads')
            ->assertOk()
            ->assertJsonCount(0, 'data.threads');
    }

    #[Test]
    public function create_direct_thread_finds_existing_or_creates_new(): void
    {
        $user = User::factory()->create();
        $friend = User::factory()->create();

        DB::table('friendships')->insert([
            ['user_id' => $user->id, 'friend_id' => $friend->id, 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $friend->id, 'friend_id' => $user->id, 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response1 = $this->postJson('/api/social-money/threads/direct', ['friendId' => $friend->id]);
        $response1->assertOk()
            ->assertJsonPath('data.isNew', true)
            ->assertJsonPath('data.thread.type', 'direct');

        $threadId = $response1->json('data.thread.id');

        $response2 = $this->postJson('/api/social-money/threads/direct', ['friendId' => $friend->id]);
        $response2->assertOk()
            ->assertJsonPath('data.isNew', false)
            ->assertJsonPath('data.thread.id', $threadId);
    }

    #[Test]
    public function create_direct_thread_requires_friendship(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $this->postJson('/api/social-money/threads/direct', ['friendId' => $stranger->id])
            ->assertForbidden();
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
