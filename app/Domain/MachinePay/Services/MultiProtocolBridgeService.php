<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Services;

/**
 * Multi-protocol bridge between X402, MPP, and AP2.
 *
 * Handles cross-protocol conversion when AP2 wraps x402 as a
 * payment method within MPP, or when agents need to select
 * between available payment protocols.
 */
class MultiProtocolBridgeService
{
    /**
     * Resolve the best payment protocol for a given context.
     *
     * @param array<string> $preferredProtocols Ordered preferences.
     *
     * @return string|null The selected protocol (x402, mpp, null).
     */
    public function resolvePaymentProtocol(array $preferredProtocols = ['x402', 'mpp']): ?string
    {
        foreach ($preferredProtocols as $protocol) {
            if ($this->isProtocolAvailable($protocol)) {
                return $protocol;
            }
        }

        return null;
    }

    /**
     * Get all available payment protocols.
     *
     * @return array<string, array{enabled: bool, type: string, description: string}>
     */
    public function getAvailableProtocols(): array
    {
        $protocols = [];

        if ($this->isProtocolAvailable('x402')) {
            $protocols['x402'] = [
                'enabled'     => true,
                'type'        => 'crypto',
                'description' => 'HTTP 402 native payments with USDC on EVM chains',
            ];
        }

        if ($this->isProtocolAvailable('mpp')) {
            $protocols['mpp'] = [
                'enabled'     => true,
                'type'        => 'multi-rail',
                'description' => 'Machine Payments Protocol with Stripe, Tempo, Lightning, Card rails',
            ];
        }

        return $protocols;
    }

    /**
     * Check if a specific protocol is available.
     */
    public function isProtocolAvailable(string $protocol): bool
    {
        return match ($protocol) {
            'x402'  => (bool) config('x402.enabled', false),
            'mpp'   => (bool) config('machinepay.enabled', false),
            default => false,
        };
    }

    /**
     * Get protocol metadata for AP2 mandate payment method resolution.
     *
     * @return array<string, mixed>
     */
    public function getProtocolMetadata(string $protocol): array
    {
        return match ($protocol) {
            'x402' => [
                'type'     => 'x402',
                'version'  => config('x402.version', 2),
                'network'  => config('x402.server.default_network', 'eip155:8453'),
                'asset'    => config('x402.server.default_asset', 'USDC'),
                'spec_url' => 'https://x402.org',
            ],
            'mpp' => [
                'type'     => 'mpp',
                'version'  => config('machinepay.version', 1),
                'rails'    => config('machinepay.server.supported_rails', []),
                'currency' => config('machinepay.server.default_currency', 'USD'),
                'spec_url' => 'https://paymentauth.org',
            ],
            default => [],
        };
    }
}
