<?php

declare(strict_types=1);

namespace App\Domain\AgentProtocol\DataObjects;

/**
 * AP2 payment method descriptor.
 *
 * Wraps x402 and MPP as payment methods within the AP2 protocol.
 * Used in mandate execution to select the payment rail.
 */
readonly class AP2PaymentMethod
{
    /**
     * @param string              $type             Payment method type (x402, mpp, fiat_transfer).
     * @param array<string,mixed> $railConfig       Rail-specific configuration.
     * @param string|null         $providerEndpoint Provider API endpoint.
     */
    public function __construct(
        public string $type,
        public array $railConfig = [],
        public ?string $providerEndpoint = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type'              => $this->type,
            'rail_config'       => $this->railConfig ?: null,
            'provider_endpoint' => $this->providerEndpoint,
        ], static fn ($v) => $v !== null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? 'x402'),
            railConfig: (array) ($data['rail_config'] ?? []),
            providerEndpoint: isset($data['provider_endpoint']) ? (string) $data['provider_endpoint'] : null,
        );
    }

    public static function x402(string $network = 'eip155:8453'): self
    {
        return new self('x402', ['network' => $network, 'asset' => 'USDC']);
    }

    public static function mpp(string $rail = 'stripe'): self
    {
        return new self('mpp', ['rail' => $rail]);
    }
}
