<?php

declare(strict_types=1);

namespace App\Domain\NedbankSendMoney\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NedbankSendMoneyClient
{
    private const TOKEN_CACHE_KEY = 'nedbank_send_money:oauth:access_token';

    private const TOKEN_CACHE_TTL_SECONDS = 3500;

    public function assertConfigured(): void
    {
        if ($this->baseUrl() === '') {
            throw new RuntimeException('Nedbank Send Money base URL is not configured.');
        }

        if (config('nedbank_send_money.client_id') === '' || config('nedbank_send_money.client_secret') === '') {
            throw new RuntimeException('Nedbank Send Money client credentials are not configured.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function initiateInbound(
        string $referenceId,
        string $amountMajor,
        string $currency,
        string $payerMsisdn,
        string $externalId,
        string $note,
    ): array {
        $this->assertConfigured();

        return $this->postJson('/sendmoney/v1/payments/inbound', [
            'reference_id' => $referenceId,
            'amount'       => $amountMajor,
            'currency'     => $currency,
            'sender'       => ['msisdn' => $payerMsisdn],
            'external_id'  => $externalId,
            'memo'         => $note,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function initiateOutbound(
        string $referenceId,
        string $amountMajor,
        string $currency,
        string $payeeMsisdn,
        string $externalId,
        string $note,
    ): array {
        $this->assertConfigured();

        return $this->postJson('/sendmoney/v1/payments/outbound', [
            'reference_id' => $referenceId,
            'amount'       => $amountMajor,
            'currency'     => $currency,
            'recipient'    => ['msisdn' => $payeeMsisdn],
            'external_id'  => $externalId,
            'memo'         => $note,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInboundStatus(string $referenceId): array
    {
        $this->assertConfigured();

        return $this->getJson('/sendmoney/v1/payments/inbound/' . rawurlencode($referenceId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getOutboundStatus(string $referenceId): array
    {
        $this->assertConfigured();

        return $this->getJson('/sendmoney/v1/payments/outbound/' . rawurlencode($referenceId));
    }

    public function getAccessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->withBasicAuth(
                (string) config('nedbank_send_money.client_id'),
                (string) config('nedbank_send_money.client_secret'),
            )
            ->post($this->baseUrl() . '/oauth2/token', ['grant_type' => 'client_credentials']);

        $this->throwIfBad($response, 'oauth token');

        /** @var array<string, mixed> $data */
        $data = $response->json() ?? [];
        $token = is_scalar($data['access_token'] ?? null) ? (string) $data['access_token'] : '';

        if ($token === '') {
            throw new RuntimeException('Nedbank Send Money returned an empty access token.');
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
                'Nedbank Send Money %s failed: HTTP %d %s',
                $context,
                $response->status(),
                substr($response->body(), 0, 200),
            ));
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('nedbank_send_money.base_url'), '/');
    }
}
