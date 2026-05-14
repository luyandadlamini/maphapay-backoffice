<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Webhooks;

use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Models\CardTransaction;
use App\Domain\CardIssuance\ValueObjects\StripeUsdToSzlConverter;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class StripeIssuingWebhookControllerTest extends TestCase
{
    public function test_invalid_signature_returns_400(): void
    {
        $issuer = Mockery::mock(CardIssuerInterface::class);
        $issuer->shouldReceive('verifyWebhookSignature')->once()->andReturnFalse();

        $this->app->instance(CardIssuerInterface::class, $issuer);
        $this->app->instance(StripeUsdToSzlConverter::class, new StripeUsdToSzlConverter(18.50));

        $this->postJson('/api/v1/cards/webhooks/stripe-issuing', [
            'id' => 'evt_test',
            'type' => 'issuing_card.updated',
        ], ['Stripe-Signature' => 't=123,v1=deadbeef'])
            ->assertStatus(400)
            ->assertJson(['error' => 'invalid signature']);
    }

    public function test_valid_ignored_event_is_recorded_and_duplicate_is_skipped(): void
    {
        $issuer = Mockery::mock(CardIssuerInterface::class);
        $issuer->shouldReceive('verifyWebhookSignature')->twice()->andReturnTrue();

        $this->app->instance(CardIssuerInterface::class, $issuer);
        $this->app->instance(StripeUsdToSzlConverter::class, new StripeUsdToSzlConverter(18.50));

        $payload = [
            'id' => 'evt_ignored_once',
            'type' => 'unhandled.event',
            'data' => ['object' => []],
        ];

        $this->postJson('/api/v1/cards/webhooks/stripe-issuing', $payload, ['Stripe-Signature' => 'valid'])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->postJson('/api/v1/cards/webhooks/stripe-issuing', $payload, ['Stripe-Signature' => 'valid'])
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->assertSame(1, DB::table('stripe_webhook_events')->where('event_id', 'evt_ignored_once')->count());
        $this->assertNotNull(DB::table('stripe_webhook_events')->where('event_id', 'evt_ignored_once')->value('processed_at'));
    }

    public function test_transaction_created_records_szl_billing_amount(): void
    {
        $issuer = Mockery::mock(CardIssuerInterface::class);
        $issuer->shouldReceive('verifyWebhookSignature')->once()->andReturnTrue();

        $this->app->instance(CardIssuerInterface::class, $issuer);
        $this->app->instance(StripeUsdToSzlConverter::class, new StripeUsdToSzlConverter(18.50));

        $card = Card::factory()->create([
            'user_id' => $this->user->id,
            'issuer' => 'stripe',
            'issuer_card_token' => 'ic_test_123',
            'status' => 'active',
        ]);

        $this->postJson('/api/v1/cards/webhooks/stripe-issuing', [
            'id' => 'evt_txn_created',
            'type' => 'issuing_transaction.created',
            'data' => [
                'object' => [
                    'id' => 'ipi_test_123',
                    'amount' => -1000,
                    'authorization' => 'iauth_test_123',
                    'card' => ['id' => 'ic_test_123'],
                    'merchant_data' => [
                        'name' => 'Stripe Test Merchant',
                        'category' => '5734',
                    ],
                ],
            ],
        ], ['Stripe-Signature' => 'valid'])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $transaction = CardTransaction::query()->where('external_id', 'ipi_test_123')->firstOrFail();

        $this->assertSame($card->id, $transaction->card_id);
        $this->assertSame('settled', $transaction->status);
        $this->assertSame(1000, $transaction->amount_cents);
        $this->assertSame('USD', $transaction->currency);
        $this->assertSame('185.00', $transaction->billing_amount);
    }
}
