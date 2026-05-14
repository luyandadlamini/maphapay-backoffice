<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Stripe\StripeClient;

class StripeIssuingRevealController extends Controller
{
    public function __construct(private readonly StripeClient $stripe)
    {
    }

    public function show(Request $request): View
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Reveal link has expired or is invalid.');
        }

        $cardId = $this->cardId($request);

        return view('stripe-cards.reveal', [
            'stripePublishableKey' => (string) config('cards.processors.stripe.publishable_key', ''),
            'stripeCardId' => $cardId,
            'ephemeralKeyUrl' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'api.v1.cards.stripe.reveal.ephemeral-key',
                now()->addMinutes(15),
                ['card' => $cardId]
            ),
        ]);
    }

    public function ephemeralKey(Request $request): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Reveal link has expired or is invalid.');
        }

        $cardId = $this->cardId($request);
        $validated = $request->validate([
            'card_id' => ['required', 'string', 'same:card'],
            'nonce' => ['required', 'string'],
        ]);

        $ephemeralKey = $this->stripe->ephemeralKeys->create(
            [
                'nonce' => $validated['nonce'],
                'issuing_card' => $cardId,
            ],
            ['stripe_version' => (string) config('cards.processors.stripe.api_version', '2026-04-22.dahlia')]
        );

        return response()->json([
            'ephemeralKeySecret' => (string) $ephemeralKey->secret,
        ])->header('Cache-Control', 'no-store');
    }

    private function cardId(Request $request): string
    {
        $cardId = $request->query('card');
        if (! is_string($cardId) || $cardId === '') {
            abort(404);
        }

        return $cardId;
    }
}
