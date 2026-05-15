<?php

declare(strict_types=1);

namespace App\Domain\Emali\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the eMali Eswatini Mobile wallet API.
 *
 * The real API spec is not publicly documented; this client mirrors a
 * plausible OAuth2 client_credentials + REST shape. In dev/test the
 * configured base URL points at the local mock controllers under
 * /__mock/wallets/emali.
 */
class EmaliClient
{
    private const TOKEN_CACHE_KEY = 'emali:oauth:access_token';

    private const TOKEN_CACHE_TTL_SECONDS = 3500;

    public function assertConfigured(): void
    {
        if ($this->baseUrl() === '') {
            throw new RuntimeException('eMali base URL is not configured.');
        }

        if (config('emali.client_id') === '' || config('emali.client_secret') === '') {
            throw new RuntimeException('eMali client credentials are not configured.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function initiateCollection(
        string $referenceId,
        string $amountMajor,
        string $currency,
        string $payerMsisdn,
        string $externalId,
        string $note,
    ): array {
        $this->assertConfigured();

        return $this->postJson('/v1/collections', [
            'reference_id' => $referenceId,
            'amount'       => $amountMajor,
            'currency'     => $currency,
            'payer'        => ['msisdn' => $payerMsisdn],
            'external_id'  => $externalId,
            'note'         => $note,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function initiateDisbursement(
        string $referenceId,
        string $amountMajor,
        string $currency,
        string $payeeMsisdn,
        string $externalId,
        string $note,
    ): array {
        $this->assertConfigured();

        return $this->postJson('/v1/disbursements', [
            'reference_id' => $referenceId,
            'amount'       => $amountMajor,
            'currency'     => $currency,
            'payee'        => ['msisdn' => $payeeMsisdn],
            'external_id'  => $externalId,
            'note'         => $note,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCollectionStatus(string $referenceId): array
    {
        $this->assertConfigured();

        return $this->getJson('/v1/collections/' . rawurlencode($referenceId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getDisbursementStatus(string $referenceId): array
    {
        $this->assertConfigured();

        return $this->getJson('/v1/disbursements/' . rawurlencode($referenceId));
    }

    public function getAccessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->withBasicAuth(
                (string) config('emali.client_id'),
                (string) config('emali.client_secret'),
            )
            ->post($this->baseUrl() . '/v1/auth/token', [
                'grant_type' => 'client_credentials',
            ]);

        $this->throwIfBad($response, 'oauth token');

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $token = is_scalar($data['access_token'] ?? null) ? (string) $data['access_token'] : '';

        if ($token === '') {
            throw new RuntimeException('eMali returned an empty access token.');
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
                'eMali %s failed: HTTP %d %s',
                $context,
                $response->status(),
                substr($response->body(), 0, 200),
            ));
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('emali.base_url'), '/');
    }
}
