<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Services;

use App\Domain\MachinePay\Models\MppMonetizedResource;

/**
 * MPP discovery service.
 *
 * Generates OpenAPI x-payment-info extensions and well-known
 * configuration documents for MPP service discovery.
 */
class MppDiscoveryService
{
    /**
     * Generate the .well-known/mpp-configuration discovery document.
     *
     * @return array<string, mixed>
     */
    public function getWellKnownConfiguration(): array
    {
        return [
            'mpp_version'     => config('machinepay.version', 1),
            'issuer'          => config('app.url'),
            'supported_rails' => config('machinepay.server.supported_rails', []),
            'currency'        => config('machinepay.server.default_currency', 'USD'),
            'endpoints'       => [
                'status'    => url('/api/v1/mpp/status'),
                'supported' => url('/api/v1/mpp/supported-rails'),
                'resources' => url('/api/v1/mpp/resources'),
                'payments'  => url('/api/v1/mpp/payments'),
            ],
            'mcp' => [
                'enabled'    => (bool) config('machinepay.mcp.enabled', true),
                'error_code' => (int) config('machinepay.mcp.error_code', -32042),
            ],
            'spec_url' => 'https://paymentauth.org',
        ];
    }

    /**
     * Generate x-payment-info OpenAPI extensions for monetized resources.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPaymentInfoExtensions(): array
    {
        $resources = MppMonetizedResource::where('is_active', true)->get();

        return $resources->map(function (MppMonetizedResource $resource): array {
            return [
                'path'           => $resource->path,
                'method'         => $resource->method,
                'x-payment-info' => [
                    'intent'      => 'charge',
                    'amount'      => $resource->amount_cents,
                    'currency'    => $resource->currency,
                    'rails'       => $resource->available_rails,
                    'description' => $resource->description,
                ],
            ];
        })->all();
    }
}
