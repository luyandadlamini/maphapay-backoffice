<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Contracts\ProviderSettler;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches provider webhook settlement to the registered ProviderSettler for
 * that provider_id. Keeps the legacy class name so the existing webhook
 * controller signature stays stable; behaviour is now a strategy dispatcher.
 */
final class MoneySettlerService
{
    /** @var array<string, ProviderSettler> */
    private array $settlers = [];

    /**
     * @param  iterable<ProviderSettler>  $settlers
     */
    public function __construct(iterable $settlers = [])
    {
        foreach ($settlers as $settler) {
            $this->settlers[$settler->providerId()] = $settler;
        }
    }

    public function register(ProviderSettler $settler): void
    {
        $this->settlers[$settler->providerId()] = $settler;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function settle(string $providerId, string $providerRequestId, string $outcome, array $payload): void
    {
        $settler = $this->settlers[$providerId] ?? null;

        if ($settler === null) {
            Log::warning('No settler registered for wallet provider', [
                'provider_id'         => $providerId,
                'provider_request_id' => $providerRequestId,
            ]);

            return;
        }

        $settler->settle($providerRequestId, $outcome, $payload);
    }
}
