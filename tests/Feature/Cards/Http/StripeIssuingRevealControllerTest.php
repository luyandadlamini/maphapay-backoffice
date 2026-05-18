<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Http;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StripeIssuingRevealControllerTest extends TestCase
{
    public function test_reveal_page_rejects_unsigned_requests(): void
    {
        $this->get('/api/stripe-cards/reveal?card=ic_test_123')
            ->assertForbidden();
    }

    public function test_signed_reveal_page_renders_stripe_elements_bootstrap(): void
    {
        config()->set('cards.processors.stripe.publishable_key', 'pk_test_123');

        $url = URL::temporarySignedRoute(
            'api.v1.cards.stripe.reveal',
            now()->addMinute(),
            ['card' => 'ic_test_123']
        );

        $this->get($url)
            ->assertOk()
            ->assertSee('https://js.stripe.com/v3/', false)
            ->assertSee('issuingCardNumberDisplay', false)
            ->assertSee('pk_test_123', false)
            ->assertSee('ic_test_123', false);
    }

    public function test_ephemeral_key_endpoint_rejects_unsigned_requests(): void
    {
        $this->postJson('/api/stripe-cards/reveal/ephemeral-key', [
            'card_id' => 'ic_test_123',
            'nonce'   => 'ephkeynonce_test_123',
        ])->assertForbidden();
    }

    public function test_ephemeral_key_endpoint_rejects_card_id_mismatch_before_calling_stripe(): void
    {
        $url = URL::temporarySignedRoute(
            'api.v1.cards.stripe.reveal.ephemeral-key',
            now()->addMinute(),
            ['card' => 'ic_test_123']
        );

        $this->postJson($url, [
            'card_id' => 'ic_other',
            'nonce'   => 'ephkeynonce_test_123',
        ])->assertUnprocessable();
    }
}
