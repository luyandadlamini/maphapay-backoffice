<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Contracts\CardIssuerInterface;
use App\Domain\CardIssuance\ValueObjects\RevealUrlResult;
use App\Models\User;

class CardRevealService
{
    private const REVEAL_TTL_MINUTES = 5;

    public function __construct(
        private readonly CardIssuerInterface $issuer
    ) {}

    public function mintRevealUrl(User $user, Card $card): RevealUrlResult
    {
        if (empty($card->issuer_card_token)) {
            // Fallback for development if token isn't properly synced yet,
            // though createCard() normally returns it. We use the card's local ID.
            $token = $card->id;
        } else {
            $token = $card->issuer_card_token;
        }

        return $this->issuer->generateRevealUrl($token, self::REVEAL_TTL_MINUTES * 60);
    }
}
