<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SocialChatCompatControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_paying_a_request_marks_the_original_request_paid_and_links_the_payment_message(): void
    {
        $requester = User::factory()->create();
        $payer = User::factory()->create();

        Sanctum::actingAs($requester, ['read', 'write', 'delete']);

        $requestResponse = $this->postJson('/api/social-money/send-request-message', [
            'friendId' => $payer->id,
            'amount' => 250,
            'note' => 'School transport',
        ]);

        $requestResponse->assertOk();
        $requestMessageId = (int) $requestResponse->json('data.messageId');

        Sanctum::actingAs($payer, ['read', 'write', 'delete']);

        $paymentResponse = $this->postJson('/api/social-money/send-payment-message', [
            'friendId' => $requester->id,
            'trx' => 'trx-chat-request-paid',
            'amount' => 250,
            'note' => 'Paid from request',
            'requestMessageId' => $requestMessageId,
        ]);

        $paymentResponse->assertOk();

        $messagesResponse = $this->getJson("/api/social-money/messages/{$requester->id}");
        $messagesResponse->assertOk();

        $messages = $messagesResponse->json('data.messages');

        $this->assertCount(2, $messages);
        $this->assertSame('request', $messages[0]['type']);
        $this->assertSame('paid', $messages[0]['request']['status']);
        $this->assertSame('payment', $messages[1]['type']);
        $this->assertSame((string) $requestMessageId, (string) ($messages[1]['payment']['requestMessageId'] ?? null));
    }
}
