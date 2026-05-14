<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use App\Domain\CardIssuance\ValueObjects\RevealUrlResult;
use App\Models\User;
use RuntimeException;

class CardRevealService
{
    private const REVEAL_TTL_MINUTES = 5;

    public function __construct(
        private readonly CardIssuerInterface $issuer
    ) {}

    public function mintRevealUrl(User $user, Card $card): RevealUrlResult
    {
        $token = $card->issuer_card_token;

        if (! is_string($token) || $token === '') {
            // A card without an issuer token is unrevealable. The Stripe driver
            // requires `ic_*` to mint a reveal page; the demo driver also needs
            // a stable token. Silent fallback to the local UUID used to produce
            // signed URLs that 404 inside the iframe.
            throw new RuntimeException(
                "Card {$card->id} has no issuer_card_token; cannot generate reveal URL."
            );
        }

        return $this->issuer->generateRevealUrl($token, self::REVEAL_TTL_MINUTES * 60);
    }
}
