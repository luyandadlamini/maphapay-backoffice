<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\MachinePay;

use App\Domain\MachinePay\Services\MppDiscoveryService;
use App\Domain\MachinePay\Services\MppRailResolverService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MppStatusController extends Controller
{
    public function __construct(
        private readonly MppRailResolverService $railResolver,
        private readonly MppDiscoveryService $discovery,
    ) {
    }

    /**
     * MPP protocol status.
     */
    public function status(): JsonResponse
    {
        $availableRails = $this->railResolver->getAvailableRailIds();

        return response()->json([
            'success' => true,
            'data'    => [
                'enabled'         => (bool) config('machinepay.enabled', false),
                'version'         => (int) config('machinepay.version', 1),
                'available_rails' => $availableRails,
                'rail_count'      => count($availableRails),
                'mcp_enabled'     => (bool) config('machinepay.mcp.enabled', true),
                'spec_url'        => 'https://paymentauth.org',
            ],
        ]);
    }

    /**
     * List supported payment rails with details.
     */
    public function supportedRails(): JsonResponse
    {
        $rails = $this->railResolver->getAvailableRails();

        $data = [];
        foreach ($rails as $id => $rail) {
            $railEnum = $rail->getRailIdentifier();
            $data[] = [
                'id'              => $id,
                'label'           => $railEnum->label(),
                'description'     => $railEnum->description(),
                'supports_fiat'   => $railEnum->supportsFiat(),
                'supports_crypto' => $railEnum->supportsCrypto(),
                'currencies'      => $railEnum->defaultCurrencies(),
                'available'       => $rail->isAvailable(),
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * .well-known/mpp-configuration discovery endpoint.
     */
    public function wellKnown(): JsonResponse
    {
        return response()->json($this->discovery->getWellKnownConfiguration());
    }
}
