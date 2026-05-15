<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Webhooks;

use App\Domain\Wallet\Providers\WalletProviderRegistry;
use App\Domain\Wallet\Services\MoneySettlerService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WalletProviderWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        WalletProviderRegistry $registry,
        MoneySettlerService $settler,
    ): Response {
        $adapter = $registry->for($provider);

        if (! $adapter->verifyWebhookSignature($request->getContent(), $request->headers->all())) {
            return response('', 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        $providerRequestId = $this->providerRequestId($payload);
        $outcome = $this->outcome($payload);

        if ($providerRequestId === '' || $outcome === '') {
            return response('', 400);
        }

        $settler->settle($provider, $providerRequestId, $outcome, $payload);

        return response('', 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerRequestId(array $payload): string
    {
        foreach (['referenceId', 'providerRequestId', 'provider_request_id'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                return (string) $payload[$key];
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function outcome(array $payload): string
    {
        return isset($payload['status']) && is_scalar($payload['status'])
            ? (string) $payload['status']
            : '';
    }
}
