<?php

declare(strict_types=1);

namespace App\Domain\StandardUnayo\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StandardUnayoClient
{
    private const TOKEN_CACHE_KEY = 'standard_unayo:oauth:access_token';

    private const TOKEN_CACHE_TTL_SECONDS = 3500;

    public function assertConfigured(): void
    {
        if ($this->baseUrl() === '') {
            throw new RuntimeException('Standard Unayo base URL is not configured.');
        }

        if (config('standard_unayo.client_id') === '' || config('standard_unayo.client_secret') === '') {
            throw new RuntimeException('Standard Unayo client credentials are not configured.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function initiateCashIn(
        string $referenceId,
        string $amountMajor,
        string $currency,
        string $payerMsisdn,
        string $externalId,
        string $note,
    ): array {
        $this->assertConfigured();

        return $this->postJson('/unayo/v1/cashin', [
            'reference_id' => $referenceId,
            'amount'       => $amountMajor,
            'currency'     => $currency,
            'payer'        => ['msisdn' => $payerMsisdn],
            'external_id'  => $externalId,
            'description'  => $note,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function initiateCashOut(
        string $referenceId,
        string $amountMajor,
        string $currency,
        string $payeeMsisdn,
        string $externalId,
        string $note,
    ): array {
        $this->assertConfigured();

        return $this->postJson('/unayo/v1/cashout', [
            'reference_id' => $referenceId,
            'amount'       => $amountMajor,
            'currency'     => $currency,
            'payee'        => ['msisdn' => $payeeMsisdn],
            'external_id'  => $externalId,
            'description'  => $note,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCashInStatus(string $referenceId): array
    {
        $this->assertConfigured();

        return $this->getJson('/unayo/v1/cashin/' . rawurlencode($referenceId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getCashOutStatus(string $referenceId): array
    {
        $this->assertConfigured();

        return $this->getJson('/unayo/v1/cashout/' . rawurlencode($referenceId));
    }

    public function getAccessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->withBasicAuth(
                (string) config('standard_unayo.client_id'),
                (string) config('standard_unayo.client_secret'),
            )
            ->post($this->baseUrl() . '/oauth/token', ['grant_type' => 'client_credentials']);

        $this->throwIfBad($response, 'oauth token');

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $token = is_scalar($data['access_token'] ?? null) ? (string) $data['access_token'] : '';

        if ($token === '') {
            throw new RuntimeException('Standard Unayo returned an empty access token.');
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, self::TOKEN_CACHE_TTL_SECONDS);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $body): array
    {
        $response = Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->asJson()
            ->post($this->baseUrl() . $path, $body);

        $this->throwIfBad($response, $path);

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $path): array
    {
        $response = Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->get($this->baseUrl() . $path);

        $this->throwIfBad($response, $path);

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];

        return $data;
    }

    private function throwIfBad(Response $response, string $context): void
    {
        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Standard Unayo %s failed: HTTP %d %s',
                $context,
                $response->status(),
                substr($response->body(), 0, 200),
            ));
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('standard_unayo.base_url'), '/');
    }
}
