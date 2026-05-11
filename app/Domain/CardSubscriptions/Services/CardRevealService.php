<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\ValueObjects\RevealUrlResult;
use App\Models\User;
use Illuminate\Support\Facades\URL;

class CardRevealService
{
    private const REVEAL_TTL_MINUTES = 5;

    public function mintRevealUrl(User $user, Card $card): RevealUrlResult
    {
        $expiresAt = now()->addMinutes(self::REVEAL_TTL_MINUTES);

        // In a real production system, this would point to a PCI-DSS compliant
        // environment or an iframe-hosted page from the card issuer.
        // For development, we point to a signed route that will return the demo details.
        $url = URL::temporarySignedRoute(
            'api.v1.cards.reveal.show-secure',
            $expiresAt,
            ['id' => $card->id]
        );

        return new RevealUrlResult(
            revealUrl: $url,
            expiresAt: $expiresAt->toDateTimeImmutable(),
            ttlSeconds: self::REVEAL_TTL_MINUTES * 60
        );
    }
}
