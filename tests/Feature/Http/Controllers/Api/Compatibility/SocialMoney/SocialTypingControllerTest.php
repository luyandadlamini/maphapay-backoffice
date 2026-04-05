<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\SocialMoney;

use App\Domain\SocialMoney\Events\Broadcast\ChatMessageSent;
use App\Domain\SocialMoney\Events\Broadcast\SocialTypingUpdated;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class SocialTypingControllerTest extends ControllerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->ensureSocialFriendshipTable();
    }

    #[Test]
    public function accepted_friends_can_broadcast_typing_updates(): void
    {
        Event::fake([SocialTypingUpdated::class]);

        $sender = User::factory()->create(['name' => 'Ava Sender']);
        $peer = User::factory()->create(['name' => 'Sam Peer']);
        $this->makeAcceptedFriends($sender->id, $peer->id);

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        $this->postJson('/api/social-money/typing', [
            'friendId' => $peer->id,
            'isTyping' => true,
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('remark', 'social_typing');

        Event::assertDispatched(SocialTypingUpdated::class, function (SocialTypingUpdated $event) use ($sender, $peer): bool {
            $payload = $event->broadcastWith();

            return $event->recipientId === $peer->id
                && $event->broadcastAs() === 'TypingUpdated'
                && $payload['kind'] === 'typing'
                && $payload['conversationType'] === 'direct'
                && $payload['conversationKey'] === (string) $sender->id
                && $payload['actorUserId'] === (string) $sender->id
                && $payload['actorDisplayName'] === 'Ava Sender'
                && $payload['isTyping'] === true
                && is_string($payload['expiresAt'] ?? null);
        });
    }

    #[Test]
    public function typing_updates_are_forbidden_when_users_are_not_accepted_friends(): void
    {
        Event::fake([SocialTypingUpdated::class]);

        $sender = User::factory()->create();
        $peer = User::factory()->create();

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        $this->postJson('/api/social-money/typing', [
            'friendId' => $peer->id,
            'isTyping' => true,
        ])->assertForbidden();

        Event::assertNotDispatched(SocialTypingUpdated::class);
    }

    #[Test]
    public function sending_a_text_message_broadcasts_the_message_and_clears_typing(): void
    {
        Event::fake([SocialTypingUpdated::class, ChatMessageSent::class]);

        $sender = User::factory()->create(['name' => 'Ava Sender']);
        $peer = User::factory()->create(['name' => 'Sam Peer']);
        $this->makeAcceptedFriends($sender->id, $peer->id);

        Sanctum::actingAs($sender, ['read', 'write', 'delete']);

        $this->postJson('/api/social-money/send', [
            'friendId' => $peer->id,
            'text'     => 'hello there',
        ])->assertOk();

        Event::assertDispatched(ChatMessageSent::class, function (ChatMessageSent $event) use ($sender, $peer): bool {
            $payload = $event->broadcastWith();

            return $event->recipientId === $peer->id
                && $event->senderId === $sender->id
                && ($payload['message']['text'] ?? null) === 'hello there';
        });

        Event::assertDispatched(SocialTypingUpdated::class, function (SocialTypingUpdated $event) use ($sender, $peer): bool {
            $payload = $event->broadcastWith();

            return $event->recipientId === $peer->id
                && $payload['conversationKey'] === (string) $sender->id
                && $payload['actorUserId'] === (string) $sender->id
                && $payload['isTyping'] === false;
        });
    }

    private function makeAcceptedFriends(int $a, int $b): void
    {
        DB::table('friendships')->insert([
            [
                'user_id'    => $a,
                'friend_id'  => $b,
                'status'     => 'accepted',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id'    => $b,
                'friend_id'  => $a,
                'status'     => 'accepted',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
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
}
