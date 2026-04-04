<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\SocialMoney;

use App\Domain\SocialMoney\Events\Broadcast\ChatMessageSent;
use App\Models\BillSplit;
use App\Models\BillSplitParticipant;
use App\Models\Message;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ThreadBillSplitControllerTest extends TestCase
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

    #[Test]
    public function create_bill_split_in_group(): void
    {
        Event::fake([ChatMessageSent::class]);
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $carol = User::factory()->create(['name' => 'Carol']);

        $thread = Thread::create(['type' => 'group', 'name' => 'Dinner', 'created_by' => $alice->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $bob->id, 'role' => 'member', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $carol->id, 'role' => 'member', 'joined_at' => now()]);

        Sanctum::actingAs($alice, ['read', 'write', 'delete']);

        $response = $this->postJson("/api/social-money/threads/{$thread->id}/bill-split", [
            'description'  => 'Pizza night',
            'totalAmount'  => 300.00,
            'splitMethod'  => 'equal',
            'participants' => [
                ['userId' => $alice->id, 'amount' => 100.00],
                ['userId' => $bob->id, 'amount' => 100.00],
                ['userId' => $carol->id, 'amount' => 100.00],
            ],
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['messageId', 'billSplitId']]);

        $this->assertDatabaseHas('bill_splits', [
            'thread_id'    => $thread->id,
            'description'  => 'Pizza night',
            'total_amount' => 300.00,
        ]);
        $this->assertSame(3, BillSplitParticipant::query()->count());
    }

    #[Test]
    public function bill_split_rejects_non_members(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $stranger = User::factory()->create();

        $thread = Thread::create(['type' => 'group', 'name' => 'Test', 'created_by' => $alice->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $alice->id, 'role' => 'admin', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $bob->id, 'role' => 'member', 'joined_at' => now()]);

        Sanctum::actingAs($alice, ['read', 'write', 'delete']);

        $this->postJson("/api/social-money/threads/{$thread->id}/bill-split", [
            'description'  => 'Test',
            'totalAmount'  => 100,
            'splitMethod'  => 'equal',
            'participants' => [
                ['userId' => $alice->id, 'amount' => 50],
                ['userId' => $stranger->id, 'amount' => 50],
            ],
        ])->assertUnprocessable();
    }

    #[Test]
    public function mark_paid_updates_participant_status(): void
    {
        Event::fake();
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);

        $thread = Thread::create(['type' => 'direct', 'created_by' => $alice->id]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $alice->id, 'role' => 'member', 'joined_at' => now()]);
        ThreadParticipant::create(['thread_id' => $thread->id, 'user_id' => $bob->id, 'role' => 'member', 'joined_at' => now()]);

        $message = Message::create([
            'thread_id'  => $thread->id,
            'sender_id'  => $alice->id,
            'type'       => 'bill_split',
            'created_at' => now(),
        ]);
        $split = BillSplit::create([
            'message_id'   => $message->id,
            'thread_id'    => $thread->id,
            'created_by'   => $alice->id,
            'description'  => 'Lunch',
            'total_amount' => 100,
            'split_method' => 'equal',
        ]);
        BillSplitParticipant::create(['bill_split_id' => $split->id, 'user_id' => $bob->id, 'amount' => 50]);

        Sanctum::actingAs($alice, ['read', 'write', 'delete']);

        $this->postJson("/api/social-money/bill-splits/{$split->id}/mark-paid", [
            'participantUserId' => $bob->id,
        ])->assertOk();

        $this->assertDatabaseHas('bill_split_participants', [
            'bill_split_id' => $split->id,
            'user_id'       => $bob->id,
            'status'        => 'paid',
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
