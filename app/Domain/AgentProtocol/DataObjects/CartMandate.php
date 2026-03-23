<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\DataObjects;

/**
 * AP2 Cart Mandate — human-present shopping mandate.
 *
 * Binds a merchant's cart contents to a shopping agent and user,
 * using W3C PaymentRequest-compatible item structures.
 */
readonly class CartMandate
{
    /**
     * @param array<array{name: string, quantity: int, price_cents: int, currency: string}> $items Cart items.
     * @param int         $totalCents       Total cart value in smallest currency unit.
     * @param string      $currency         ISO 4217 currency code.
     * @param string      $merchantDid      Merchant agent DID.
     * @param string      $shoppingAgentDid Shopping agent DID.
     * @param string|null $expiresAt        RFC 3339 expiry.
     * @param string|null $merchantName     Human-readable merchant name.
     * @param string|null $cartId           Unique cart identifier.
     */
    public function __construct(
        public array $items,
        public int $totalCents,
        public string $currency,
        public string $merchantDid,
        public string $shoppingAgentDid,
        public ?string $expiresAt = null,
        public ?string $merchantName = null,
        public ?string $cartId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'items'              => $this->items,
            'total_cents'        => $this->totalCents,
            'currency'           => $this->currency,
            'merchant_did'       => $this->merchantDid,
            'shopping_agent_did' => $this->shoppingAgentDid,
            'expires_at'         => $this->expiresAt,
            'merchant_name'      => $this->merchantName,
            'cart_id'            => $this->cartId,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            items: (array) ($data['items'] ?? []),
            totalCents: (int) ($data['total_cents'] ?? 0),
            currency: (string) ($data['currency'] ?? 'USD'),
            merchantDid: (string) ($data['merchant_did'] ?? ''),
            shoppingAgentDid: (string) ($data['shopping_agent_did'] ?? ''),
            expiresAt: isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            merchantName: isset($data['merchant_name']) ? (string) $data['merchant_name'] : null,
            cartId: isset($data['cart_id']) ? (string) $data['cart_id'] : null,
        );
    }
}
