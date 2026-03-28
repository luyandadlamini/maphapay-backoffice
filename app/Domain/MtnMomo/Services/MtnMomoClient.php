<?php

declare(strict_types=1);

namespace App\Domain\MtnMomo\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin MTN MoMo Open API client (collection + disbursement).
 *
 * @see https://momodeveloper.mtn.com/ (API reference)
 */
class MtnMomoClient
{
    private const TOKEN_CACHE_TTL_SECONDS = 3500;

    public function assertConfigured(): void
    {
        if ($this->baseUrl() === '') {
            throw new RuntimeException('MTN MoMo base URL is not configured.');
        }

        if (config('mtn_momo.subscription_key') === '') {
            throw new RuntimeException('MTN MoMo subscription key is not configured.');
        }

        if (config('mtn_momo.api_user') === '' || config('mtn_momo.api_key') === '') {
            throw new RuntimeException('MTN MoMo API user/key is not configured.');
        }
    }

    public function requestToPay(
        string $referenceId,
        string $amount,
        string $currency,
        string $payerMsisdn,
        string $externalId,
        string $payerMessage,
        string $payeeNote,
    ): void {
        $this->assertConfigured();
        $token = $this->getCollectionAccessToken();
        $url = $this->baseUrl() . '/collection/v1_0/requesttopay';

        $response = Http::withToken($token)
            ->withHeaders($this->defaultHeaders($referenceId))
            ->acceptJson()
            ->asJson()
            ->post($url, [
                'amount'     => $amount,
                'currency'   => $currency,
                'externalId' => $externalId,
                'payer'      => [
                    'partyIdType' => 'MSISDN',
                    'partyId'     => $this->normaliseMsisdn($payerMsisdn),
                ],
                'payerMessage' => $payerMessage,
                'payeeNote'    => $payeeNote,
            ]);

        $this->throwUnlessAccepted($response, 'requesttopay');
    }

    public function disburse(
        string $referenceId,
        string $amount,
        string $currency,
        string $payeeMsisdn,
        string $externalId,
        string $payerMessage,
        string $payeeNote,
    ): void {
        $this->assertConfigured();
        $token = $this->getDisbursementAccessToken();
        $url = $this->baseUrl() . '/disbursement/v1_0/transfer';

        $response = Http::withToken($token)
            ->withHeaders($this->defaultHeaders($referenceId))
            ->acceptJson()
            ->asJson()
            ->post($url, [
                'amount'     => $amount,
                'currency'   => $currency,
                'externalId' => $externalId,
                'payee'      => [
                    'partyIdType' => 'MSISDN',
                    'partyId'     => $this->normaliseMsisdn($payeeMsisdn),
                ],
                'payerMessage' => $payerMessage,
                'payeeNote'    => $payeeNote,
            ]);

        $this->throwUnlessAccepted($response, 'disbursement');
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequestToPayStatus(string $referenceId): array
    {
        $this->assertConfigured();
        $token = $this->getCollectionAccessToken();
        $url = $this->baseUrl() . '/collection/v1_0/requesttopay/' . $referenceId;

        $response = Http::withToken($token)
            ->withHeaders($this->statusHeaders())
            ->acceptJson()
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('MTN requesttopay status failed: HTTP ' . $response->status());
        }

        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransferStatus(string $referenceId): array
    {
        $this->assertConfigured();
        $token = $this->getDisbursementAccessToken();
        $url = $this->baseUrl() . '/disbursement/v1_0/transfer/' . $referenceId;

        $response = Http::withToken($token)
            ->withHeaders($this->statusHeaders())
            ->acceptJson()
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('MTN transfer status failed: HTTP ' . $response->status());
        }

        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('mtn_momo.base_url'), '/');
    }

    private function getCollectionAccessToken(): string
    {
        return Cache::remember('mtn_momo:collection_token_v1', self::TOKEN_CACHE_TTL_SECONDS, function (): string {
            return $this->fetchToken('/collection/token/');
        });
    }

    private function getDisbursementAccessToken(): string
    {
        return Cache::remember('mtn_momo:disbursement_token_v1', self::TOKEN_CACHE_TTL_SECONDS, function (): string {
            return $this->fetchToken('/disbursement/token/');
        });
    }

    private function fetchToken(string $path): string
    {
        $url = $this->baseUrl() . $path;
        $response = Http::withBasicAuth(
            (string) config('mtn_momo.api_user'),
            (string) config('mtn_momo.api_key'),
        )
            ->withHeaders([
                'Ocp-Apim-Subscription-Key' => (string) config('mtn_momo.subscription_key'),
            ])
            ->asForm()
            ->post($url, []);

        if (! $response->successful()) {
            throw new RuntimeException('MTN OAuth token request failed: HTTP ' . $response->status());
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('MTN OAuth token response missing access_token.');
        }

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function defaultHeaders(string $referenceId): array
    {
        return [
            'X-Reference-Id'            => $referenceId,
            'X-Target-Environment'      => (string) config('mtn_momo.target_environment'),
            'Ocp-Apim-Subscription-Key' => (string) config('mtn_momo.subscription_key'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function statusHeaders(): array
    {
        return [
            'X-Target-Environment'      => (string) config('mtn_momo.target_environment'),
            'Ocp-Apim-Subscription-Key' => (string) config('mtn_momo.subscription_key'),
        ];
    }

    private function throwUnlessAccepted(Response $response, string $operation): void
    {
        if ($response->status() === 202 || $response->successful()) {
            return;
        }

        throw new RuntimeException(
            "MTN {$operation} failed: HTTP {$response->status()} " . $response->body(),
        );
    }

    private function normaliseMsisdn(string $msisdn): string
    {
        $digits = preg_replace('/\D+/', '', $msisdn) ?? '';

        return $digits;
    }
}
