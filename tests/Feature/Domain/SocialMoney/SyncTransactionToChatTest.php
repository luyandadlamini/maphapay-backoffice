<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\SocialMoney;

use App\Domain\SocialMoney\Events\Broadcast\ChatMessageSent;
use App\Domain\SocialMoney\Services\SyncTransactionToChatService;
use App\Models\Message;
use App\Models\MoneyRequest;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

class SyncTransactionToChatTest extends TestCase
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
        $this->ensureMessagesIdempotencyKeyExtended();
        $this->ensureMoneyRequestsTable();
        Event::fake([ChatMessageSent::class]);
    }

    private function service(): SyncTransactionToChatService
    {
        return app(SyncTransactionToChatService::class);
    }

    private function makeFriends(int $a, int $b): void
    {
        DB::table('friendships')->insert([
            ['user_id' => $a, 'friend_id' => $b, 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $b, 'friend_id' => $a, 'status' => 'accepted', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    #[Test]
    public function payment_message_posted_for_friends_with_existing_thread(): void
    {
        $sender = User::factory()->create(['name' => 'Alice']);
        $recipient = User::factory()->create(['name' => 'Bob']);
        $this->makeFriends($sender->id, $recipient->id);

        $thread = Thread::create(['type' => 'direct', 'created_by' => $sender->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $sender->id, 'role' => 'member', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $recipient->id, 'role' => 'member', 'joined_at' => now()]);

        $authTxnId = Str::uuid()->toString();
        $this->service()->postPaymentMessage(
            senderUserId: $sender->id,
            recipientUserId: $recipient->id,
            amount: 250.0,
            assetCode: 'SZL',
            note: 'Lunch',
            authorizedTransactionId: $authTxnId,
        );

        $this->assertDatabaseHas('messages', [
            'thread_id'       => $thread->id,
            'type'            => 'payment',
            'idempotency_key' => "tx:{$authTxnId}",
        ]);
        Event::assertDispatched(ChatMessageSent::class, fn (ChatMessageSent $e) => $e->recipientId === $recipient->id);
    }

    #[Test]
    public function no_message_when_users_are_not_friends(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        // intentionally no friendship row

        $authTxnId = Str::uuid()->toString();
        $this->service()->postPaymentMessage(
            senderUserId: $sender->id,
            recipientUserId: $recipient->id,
            amount: 100.0,
            assetCode: 'SZL',
            note: null,
            authorizedTransactionId: $authTxnId,
        );

        // Scoped to this test's transaction — global counts would catch leftover rows
        // from prior tests since the base TestCase does not RefreshDatabase.
        $this->assertDatabaseMissing('messages', ['idempotency_key' => "tx:{$authTxnId}"]);
        $threadId = DB::table('thread_participants as tp1')
            ->join('thread_participants as tp2', 'tp1.thread_id', '=', 'tp2.thread_id')
            ->where('tp1.user_id', $sender->id)
            ->where('tp2.user_id', $recipient->id)
            ->value('tp1.thread_id');
        $this->assertNull($threadId);
        Event::assertNotDispatched(ChatMessageSent::class);
    }

    #[Test]
    public function thread_is_auto_created_with_system_preamble(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $this->makeFriends($sender->id, $recipient->id);

        $authTxnId = Str::uuid()->toString();
        $this->service()->postPaymentMessage(
            senderUserId: $sender->id,
            recipientUserId: $recipient->id,
            amount: 50.0,
            assetCode: 'SZL',
            note: null,
            authorizedTransactionId: $authTxnId,
        );

        $threadId = DB::table('thread_participants as tp1')
            ->join('thread_participants as tp2', 'tp1.thread_id', '=', 'tp2.thread_id')
            ->join('threads', 'threads.id', '=', 'tp1.thread_id')
            ->where('threads.type', 'direct')
            ->where('tp1.user_id', $sender->id)
            ->where('tp2.user_id', $recipient->id)
            ->value('tp1.thread_id');
        $this->assertNotNull($threadId);
        $participantCount = DB::table('thread_participants')->where('thread_id', $threadId)->count();
        $this->assertEquals(2, $participantCount);
        $this->assertDatabaseHas('messages', ['thread_id' => $threadId, 'type' => 'system']);
        $this->assertDatabaseHas('messages', ['thread_id' => $threadId, 'type' => 'payment', 'idempotency_key' => "tx:{$authTxnId}"]);
    }

    #[Test]
    public function duplicate_call_with_same_authorized_transaction_id_is_idempotent(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $this->makeFriends($sender->id, $recipient->id);

        $authTxnId = Str::uuid()->toString();
        $args = [
            'senderUserId'            => $sender->id,
            'recipientUserId'         => $recipient->id,
            'amount'                  => 75.0,
            'assetCode'               => 'SZL',
            'note'                    => null,
            'authorizedTransactionId' => $authTxnId,
        ];

        $this->service()->postPaymentMessage(...$args);
        $this->service()->postPaymentMessage(...$args);

        $this->assertEquals(1, Message::where('idempotency_key', "tx:{$authTxnId}")->count());
    }

    #[Test]
    public function request_message_posted_then_marked_paid_in_place(): void
    {
        $requester = User::factory()->create();
        $recipient = User::factory()->create();
        $this->makeFriends($requester->id, $recipient->id);

        $req = MoneyRequest::create([
            'id'                => Str::uuid()->toString(),
            'requester_user_id' => $requester->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => 120.0,
            'asset_code'        => 'SZL',
            'note'              => 'Dinner',
            'status'            => MoneyRequest::STATUS_PENDING,
        ]);

        $this->service()->postRequestMessage($req);

        $msg = Message::where('idempotency_key', "mr:{$req->id}:created")->firstOrFail();
        $this->assertEquals('request', $msg->type);
        $this->assertEquals('pending', $msg->payload['status']);

        $this->service()->markRequestPaid($req);
        $msg->refresh();
        $this->assertEquals('paid', $msg->payload['status']);
    }

    #[Test]
    public function decline_posts_system_message_and_flips_pending_bubble_only(): void
    {
        $requester = User::factory()->create();
        $recipient = User::factory()->create();
        $this->makeFriends($requester->id, $recipient->id);

        $req = MoneyRequest::create([
            'id'                => Str::uuid()->toString(),
            'requester_user_id' => $requester->id,
            'recipient_user_id' => $recipient->id,
            'amount'            => 60.0,
            'asset_code'        => 'SZL',
            'status'            => MoneyRequest::STATUS_PENDING,
        ]);

        $this->service()->postRequestMessage($req);
        $this->service()->postRequestDeclined($req);

        $this->assertDatabaseHas('messages', [
            'idempotency_key' => "mr:{$req->id}:declined",
            'type'            => 'system',
        ]);
        $original = Message::where('idempotency_key', "mr:{$req->id}:created")->firstOrFail();
        $this->assertEquals('declined', $original->payload['status']);
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

    private function ensureMessagesIdempotencyKeyExtended(): void
    {
        // Apply the column-extension migration so deterministic keys longer than 36 chars fit.
        $migration = require base_path('database/migrations/2026_05_15_000001_extend_messages_idempotency_key_length.php');
        try {
            $migration->up();
        } catch (Throwable) {
            // SQLite alter-column quirks: column is already TEXT-equivalent in tests, no-op is safe.
        }
    }

    private function ensureMoneyRequestsTable(): void
    {
        if (Schema::hasTable('money_requests')) {
            return;
        }
        $migration = require base_path('database/migrations/2026_03_28_150000_create_money_requests_table.php');
        $migration->up();

        $columnsMigration = base_path('database/migrations/2026_04_05_120000_add_payment_link_columns_to_money_requests_table.php');
        if (is_file($columnsMigration)) {
            (require $columnsMigration)->up();
        }
    }
}
