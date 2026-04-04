<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\BillSplit;
use App\Models\BillSplitParticipant;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SocialThreadModelsTest extends TestCase
{
    #[Test]
    public function thread_exposes_the_expected_relations_and_type_helpers(): void
    {
        $thread = new Thread(['type' => 'group']);

        $this->assertInstanceOf(BelongsTo::class, $thread->creator());
        $this->assertInstanceOf(HasMany::class, $thread->participants());
        $this->assertInstanceOf(HasMany::class, $thread->activeParticipants());
        $this->assertInstanceOf(HasMany::class, $thread->messages());
        $this->assertInstanceOf(HasOne::class, $thread->latestMessage());
        $this->assertInstanceOf(HasMany::class, $thread->billSplits());
        $this->assertInstanceOf(HasMany::class, $thread->reads());
        $this->assertTrue($thread->isGroup());
        $this->assertFalse($thread->isDirect());
    }

    #[Test]
    public function thread_participant_exposes_expected_relations_and_state_helpers(): void
    {
        $participant = new ThreadParticipant(['role' => 'admin']);

        $this->assertInstanceOf(BelongsTo::class, $participant->thread());
        $this->assertInstanceOf(BelongsTo::class, $participant->user());
        $this->assertInstanceOf(BelongsTo::class, $participant->addedByUser());
        $this->assertTrue($participant->isActive());
        $this->assertTrue($participant->isAdmin());
    }

    #[Test]
    public function message_exposes_expected_relations(): void
    {
        $message = new Message();

        $this->assertInstanceOf(BelongsTo::class, $message->thread());
        $this->assertInstanceOf(BelongsTo::class, $message->sender());
        $this->assertInstanceOf(HasOne::class, $message->billSplit());
    }

    #[Test]
    public function message_read_exposes_expected_relations(): void
    {
        $messageRead = new MessageRead();

        $this->assertInstanceOf(BelongsTo::class, $messageRead->thread());
        $this->assertInstanceOf(BelongsTo::class, $messageRead->user());
    }

    #[Test]
    public function bill_split_exposes_expected_relations_and_active_helper(): void
    {
        $billSplit = new BillSplit(['status' => 'active']);

        $this->assertInstanceOf(BelongsTo::class, $billSplit->message());
        $this->assertInstanceOf(BelongsTo::class, $billSplit->thread());
        $this->assertInstanceOf(BelongsTo::class, $billSplit->creator());
        $this->assertInstanceOf(HasMany::class, $billSplit->participants());
        $this->assertTrue($billSplit->isActive());
    }

    #[Test]
    public function bill_split_participant_exposes_expected_relations_and_paid_helper(): void
    {
        $participant = new BillSplitParticipant(['status' => 'paid']);

        $this->assertInstanceOf(BelongsTo::class, $participant->billSplit());
        $this->assertInstanceOf(BelongsTo::class, $participant->user());
        $this->assertTrue($participant->isPaid());
    }
}
