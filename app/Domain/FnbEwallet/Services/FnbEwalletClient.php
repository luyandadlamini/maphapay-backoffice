<?php

declare(strict_types=1);

namespace App\Domain\FnbEwallet\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the FNB eWallet API.
 *
 * Real API spec is bank-internal; this mirrors a plausible OAuth2 +
 * REST shape. In dev/test base_url points at /__mock/wallets/fnb-ewallet.
 */
class FnbEwalletClient
{
    private const TOKEN_CACHE_KEY = 'fnb_ewallet:oauth:access_token';

    private const TOKEN_CACHE_TTL_SECONDS = 3500;

    public function assertConfigured(): void
    {
        if ($this->baseUrl() === '') {
            throw new RuntimeException('FNB eWallet base URL is not configured.');
        }

        if (config('fnb_ewallet.client_id') === '' || config('fnb_ewallet.client_secret') === '') {
            throw new RuntimeException('FNB eWallet client credentials are not configured.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function initiateCredit(
        string $referenceId,
        string $amountMajor,
        string $currency,
        string $payerMobile,
        string $externalId,
        string $note,
    ): array {
        $this->assertConfigured();

        return $this->postJson('/wallets/v1/credits', [
            'reference_id' => $referenceId,
            'amount'       => $amountMajor,
            'currency'     => $currency,
            'payer'        => ['mobile' => $payerMobile],
            'external_id'  => $externalId,
            'narration'    => $note,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function initiateTransfer(
        string $referenceId,
        string $amountMajor,
        string $currency,
        string $payeeMobile,
        string $externalId,
        string $note,
    ): array {
        $this->assertConfigured();

        return $this->postJson('/wallets/v1/transfers', [
            'reference_id' => $referenceId,
            'amount'       => $amountMajor,
            'currency'     => $currency,
            'payee'        => ['mobile' => $payeeMobile],
            'external_id'  => $externalId,
            'narration'    => $note,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCreditStatus(string $referenceId): array
    {
        $this->assertConfigured();

        return $this->getJson('/wallets/v1/credits/' . rawurlencode($referenceId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransferStatus(string $referenceId): array
    {
        $this->assertConfigured();

        return $this->getJson('/wallets/v1/transfers/' . rawurlencode($referenceId));
    }

    public function getAccessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->withBasicAuth(
                (string) config('fnb_ewallet.client_id'),
                (string) config('fnb_ewallet.client_secret'),
            )
            ->post($this->baseUrl() . '/oauth/v2/token', [
                'grant_type' => 'client_credentials',
                'scope'      => 'wallets:read wallets:write',
            ]);

        $this->throwIfBad($response, 'oauth token');

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $token = is_scalar($data['access_token'] ?? null) ? (string) $data['access_token'] : '';

        if ($token === '') {
            throw new RuntimeException('FNB eWallet returned an empty access token.');
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
                'FNB eWallet %s failed: HTTP %d %s',
                $context,
                $response->status(),
                substr($response->body(), 0, 200),
            ));
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('fnb_ewallet.base_url'), '/');
    }
}
