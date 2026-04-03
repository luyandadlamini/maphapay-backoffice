<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\SocialMoney\Services;

use App\Domain\SocialMoney\Services\SocialRequestMessageService;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

class SocialRequestMessageServiceTest extends DomainTestCase
{
    #[Test]
    public function it_creates_a_single_idempotent_chat_message_per_money_request(): void
    {
        $requester = User::factory()->create();
        $recipient = User::factory()->create();
        $moneyRequest = MoneyRequest::query()->create([
            'id' => (string) Str::uuid(),
            'requester_user_id' => $requester->id,
            'recipient_user_id' => $recipient->id,
            'amount' => '22.50',
            'asset_code' => 'SZL',
            'note' => 'Lunch',
            'status' => MoneyRequest::STATUS_PENDING,
        ]);

        $service = app(SocialRequestMessageService::class);

        $firstId = $service->ensureForMoneyRequest($moneyRequest, $requester->id, $recipient->id);
        $secondId = $service->ensureForMoneyRequest($moneyRequest, $requester->id, $recipient->id);

        $this->assertSame($firstId, $secondId);

        $messages = Cache::get(sprintf('social_chat:%d:%d', min($requester->id, $recipient->id), max($requester->id, $recipient->id)), []);
        $this->assertIsArray($messages);
        $this->assertCount(1, $messages);
        $this->assertSame('request', $messages[0]['type'] ?? null);
        $this->assertSame((string) $moneyRequest->id, $messages[0]['request']['moneyRequestId'] ?? null);
    }
}
