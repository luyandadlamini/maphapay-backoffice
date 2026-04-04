<?php

declare(strict_types=1);

namespace Tests\Feature\SocialMoney;

use App\Domain\SocialMoney\Events\Broadcast\ChatMessageSent;
use App\Domain\SocialMoney\Events\Broadcast\GroupUpdated;
use App\Domain\SocialMoney\Events\Broadcast\SocialTypingUpdated;
use App\Domain\SocialMoney\Services\SystemMessageService;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BroadcastEventsAndSystemMessageServiceTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    #[Test]
    public function chat_message_sent_broadcasts_the_thread_aware_payload(): void
    {
        $event = new ChatMessageSent(
            recipientId: 10,
            threadId: 22,
            threadType: 'group',
            senderId: 5,
            senderName: 'Alice',
            messageId: 99,
            messageType: 'system',
            preview: 'Alice added Bob',
            requestSnapshot: ['status' => 'pending'],
        );

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-chat.10', $channels[0]->name);
        $this->assertSame('ChatMessageSent', $event->broadcastAs());
        $this->assertSame([
            'threadId'        => '22',
            'threadType'      => 'group',
            'senderId'        => '5',
            'senderName'      => 'Alice',
            'messageId'       => '99',
            'messageType'     => 'system',
            'preview'         => 'Alice added Bob',
            'requestSnapshot' => ['status' => 'pending'],
        ], $event->broadcastWith());
    }

    #[Test]
    public function social_typing_updated_broadcasts_by_thread_id(): void
    {
        $event = new SocialTypingUpdated(
            recipientId: 10,
            threadId: 22,
            actorUserId: 5,
            actorDisplayName: 'Alice',
            isTyping: true,
            expiresAt: '2026-04-04T10:00:00Z',
        );

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-chat.10', $channels[0]->name);
        $this->assertSame('TypingUpdated', $event->broadcastAs());
        $this->assertSame([
            'threadId'         => '22',
            'actorUserId'      => '5',
            'actorDisplayName' => 'Alice',
            'isTyping'         => true,
            'expiresAt'        => '2026-04-04T10:00:00Z',
        ], $event->broadcastWith());
    }

    #[Test]
    public function group_updated_broadcasts_the_expected_payload(): void
    {
        $event = new GroupUpdated(
            recipientId: 10,
            threadId: 22,
            action: 'member_added',
            actorId: 5,
            actorName: 'Alice',
            targetUserId: 7,
            targetUserName: 'Bob',
            metadata: ['role' => 'member'],
        );

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-chat.10', $channels[0]->name);
        $this->assertSame('GroupUpdated', $event->broadcastAs());
        $this->assertSame([
            'threadId'       => '22',
            'action'         => 'member_added',
            'actorId'        => '5',
            'actorName'      => 'Alice',
            'targetUserId'   => '7',
            'targetUserName' => 'Bob',
            'metadata'       => ['role' => 'member'],
        ], $event->broadcastWith());
    }

    #[Test]
    public function system_message_service_creates_system_messages_with_payload(): void
    {
        $this->ensureSocialThreadTables();

        $creator = User::factory()->create(['name' => 'Alice']);
        $target = User::factory()->create(['name' => 'Bob']);

        $thread = Thread::create([
            'type'             => 'group',
            'name'             => 'Roommates',
            'created_by'       => $creator->id,
            'max_participants' => 15,
        ]);

        $service = new SystemMessageService();
        $message = $service->memberAdded($thread, $creator->id, $target->id, $creator->name, $target->name);

        $this->assertSame('system', $message->type);
        $this->assertSame('Alice added Bob', $message->text);
        $this->assertSame([
            'action'       => 'member_added',
            'targetUserId' => $target->id,
        ], $message->payload);
        $this->assertDatabaseHas('messages', [
            'id'        => $message->id,
            'thread_id' => $thread->id,
            'sender_id' => $creator->id,
            'type'      => 'system',
            'text'      => 'Alice added Bob',
        ]);
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
