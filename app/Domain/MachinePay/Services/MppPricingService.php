<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Services;

use App\Domain\MachinePay\DataObjects\MonetizedResourceConfig;
use App\Domain\MachinePay\Models\MppMonetizedResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Maps routes to MPP pricing configurations.
 *
 * Looks up monetized resource records from the database (cached)
 * and builds MonetizedResourceConfig DTOs for the middleware.
 */
class MppPricingService
{
    private const CACHE_TTL = 60; // seconds

    /**
     * Get the monetization config for a request, if any.
     */
    public function getRouteConfig(Request $request): ?MonetizedResourceConfig
    {
        $method = strtoupper($request->method());
        $path = ltrim($request->path(), '/');

        $cacheKey = "mpp_route:{$method}:{$path}";

        /** @var MonetizedResourceConfig|null $config */
        $config = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($method, $path): ?MonetizedResourceConfig {
            $endpoint = MppMonetizedResource::where('method', $method)
                ->where('path', $path)
                ->where('is_active', true)
                ->first();

            if ($endpoint === null) {
                return null;
            }

            return new MonetizedResourceConfig(
                method: (string) $endpoint->method,
                path: (string) $endpoint->path,
                amountCents: (int) $endpoint->amount_cents,
                currency: (string) $endpoint->currency,
                availableRails: (array) $endpoint->available_rails,
                description: $endpoint->description,
                mimeType: $endpoint->mime_type,
            );
        });

        return $config;
    }
}
