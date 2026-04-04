<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\SocialMoney;

use App\Domain\SocialMoney\Events\Broadcast\ChatMessageSent;
use App\Domain\SocialMoney\Events\Broadcast\SocialTypingUpdated;
use App\Models\Message;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MessageControllerTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSocialThreadTables();
    }

    private function createDirectThread(User $a, User $b): Thread
    {
        $thread = Thread::create(['type' => 'direct', 'created_by' => $a->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $a->id, 'role' => 'member', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $b->id, 'role' => 'member', 'joined_at' => now()]);

        return $thread;
    }

    #[Test]
    public function send_text_message_and_broadcasts(): void
    {
        Event::fake([ChatMessageSent::class]);
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $thread = $this->createDirectThread($alice, $bob);

        Sanctum::actingAs($alice, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/social-money/threads/{$thread->id}/send", [
            'type' => 'text',
            'text' => 'Hello Bob!',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure(['data' => ['messageId']]);

        $this->assertDatabaseHas('messages', [
            'thread_id' => $thread->id,
            'sender_id' => $alice->id,
            'type'      => 'text',
            'text'      => 'Hello Bob!',
        ]);

        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($bob, $thread): bool {
            return $event->recipientId === $bob->id && $event->threadId === $thread->id;
        });
    }

    #[Test]
    public function non_participant_cannot_send_message(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $stranger = User::factory()->create();
        $thread = $this->createDirectThread($alice, $bob);

        Sanctum::actingAs($stranger, ['read', 'write', 'delete']);

        $this->postJson("/api/social-money/threads/{$thread->id}/send", [
            'type' => 'text',
            'text' => 'Hi',
        ])->assertForbidden();
    }

    #[Test]
    public function idempotent_send_returns_same_message(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create();
        $thread = $this->createDirectThread($alice, $bob);

        Sanctum::actingAs($alice, ['read', 'write', 'delete']);

        $key = fake()->uuid();

        $firstResponse = $this->postJson("/api/social-money/threads/{$thread->id}/send", [
            'type'           => 'text',
            'text'           => 'Hello',
            'idempotencyKey' => $key,
        ]);
        $secondResponse = $this->postJson("/api/social-money/threads/{$thread->id}/send", [
            'type'           => 'text',
            'text'           => 'Hello',
            'idempotencyKey' => $key,
        ]);

        $this->assertSame($firstResponse->json('data.messageId'), $secondResponse->json('data.messageId'));
        $this->assertSame(1, Message::query()->where('thread_id', $thread->id)->count());
    }

    #[Test]
    public function list_messages_with_cursor_pagination(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $thread = $this->createDirectThread($alice, $bob);

        for ($i = 1; $i <= 5; $i++) {
            Message::create([
                'thread_id'  => $thread->id,
                'sender_id'  => $alice->id,
                'type'       => 'text',
                'text'       => "Message {$i}",
                'created_at' => now()->addMinutes($i),
            ]);
        }

        Sanctum::actingAs($alice, ['read', 'write', 'delete']);

        $response = $this->getJson("/api/social-money/threads/{$thread->id}/messages?limit=3");
        $response->assertOk();
        $messages = $response->json('data.messages');
        $this->assertCount(3, $messages);
        $this->assertSame('Message 5', $messages[0]['text']);
        $this->assertNotNull($response->json('data.nextCursor'));

        $cursor = $response->json('data.nextCursor');
        $response2 = $this->getJson("/api/social-money/threads/{$thread->id}/messages?cursor={$cursor}&limit=3");
        $messages2 = $response2->json('data.messages');
        $this->assertCount(2, $messages2);
        $this->assertSame('Message 2', $messages2[0]['text']);
        $this->assertNull($response2->json('data.nextCursor'));
    }

    #[Test]
    public function mark_read_updates_message_reads(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $thread = $this->createDirectThread($alice, $bob);
        $message = Message::create([
            'thread_id'  => $thread->id,
            'sender_id'  => $bob->id,
            'type'       => 'text',
            'text'       => 'Hi',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($alice, ['read', 'write', 'delete']);

        $this->postJson("/api/social-money/threads/{$thread->id}/read", [
            'lastReadMessageId' => $message->id,
        ])->assertOk();

        $this->assertDatabaseHas('message_reads', [
            'thread_id'            => $thread->id,
            'user_id'              => $alice->id,
            'last_read_message_id' => $message->id,
        ]);
    }

    #[Test]
    public function typing_broadcasts_to_all_other_participants(): void
    {
        Event::fake([SocialTypingUpdated::class]);
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create();
        $carol = User::factory()->create();

        $thread = Thread::create(['type' => 'group', 'name' => 'Test', 'created_by' => $alice->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $bob->id, 'role' => 'member', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $carol->id, 'role' => 'member', 'joined_at' => now()]);

        Sanctum::actingAs($alice, ['read', 'write', 'delete']);

        $this->postJson("/api/social-money/threads/{$thread->id}/typing", ['isTyping' => true])
            ->assertOk();

        Event::assertDispatched(SocialTypingUpdated::class, 2);
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
