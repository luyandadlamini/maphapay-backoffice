<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Mock;

use App\Domain\Wallet\Mock\Exceptions\InsufficientMockBalanceException;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

/**
 * Stateful Redis-backed store for the mock wallet layer.
 *
 * Keys live under `mock:wallet:{providerId}:*` with a 24h TTL so test runs
 * self-clean. The debit/credit operations are atomic per Redis HINCRBY;
 * overdraft is detected via a check-and-rollback because the mock is only
 * exercised by single-process tests and dev workflows.
 */
final class MockWalletStore
{
    public const TTL_SECONDS = 86_400;

    public const HISTORY_LIMIT = 100;

    /**
     * Return the account record, seeding from provider config if absent.
     *
     * @return array{balance_minor: int, currency: string, status: string, created_at: int}
     */
    public function accountOrSeed(string $providerId, string $accountRef): array
    {
        $key = $this->accountKey($providerId, $accountRef);
        $record = Redis::hgetall($key);

        if (! empty($record) && isset($record['balance_minor'])) {
            return $this->hydrateAccount($record);
        }

        $seed = (int) config("wallet_mocks.providers.{$providerId}.default_seed_balance_minor", 0);
        $currency = (string) config("wallet_mocks.providers.{$providerId}.currency", 'SZL');
        $createdAt = time();

        Redis::hmset($key, [
            'balance_minor' => $seed,
            'currency'      => $currency,
            'status'        => 'active',
            'created_at'    => $createdAt,
        ]);
        Redis::expire($key, self::TTL_SECONDS);

        return [
            'balance_minor' => $seed,
            'currency'      => $currency,
            'status'        => 'active',
            'created_at'    => $createdAt,
        ];
    }

    public function getBalance(string $providerId, string $accountRef): int
    {
        $value = Redis::hget($this->accountKey($providerId, $accountRef), 'balance_minor');

        return $value === null ? 0 : (int) $value;
    }

    public function setBalance(
        string $providerId,
        string $accountRef,
        int $amountMinor,
        ?string $currency = null,
    ): int {
        $key = $this->accountKey($providerId, $accountRef);

        if (! Redis::exists($key)) {
            $this->accountOrSeed($providerId, $accountRef);
        }

        Redis::hset($key, 'balance_minor', $amountMinor);

        if ($currency !== null) {
            Redis::hset($key, 'currency', $currency);
        }

        Redis::expire($key, self::TTL_SECONDS);

        return $amountMinor;
    }

    public function creditAccount(string $providerId, string $accountRef, int $amountMinor): int
    {
        if ($amountMinor < 0) {
            throw new InvalidArgumentException('Credit amount must be non-negative.');
        }

        $key = $this->accountKey($providerId, $accountRef);

        if (! Redis::exists($key)) {
            $this->accountOrSeed($providerId, $accountRef);
        }

        $next = (int) Redis::hincrby($key, 'balance_minor', $amountMinor);
        Redis::expire($key, self::TTL_SECONDS);

        return $next;
    }

    /**
     * Atomically debit; throw if the debit would overdraw the mock account.
     *
     * Implemented as a check-and-rollback on top of HINCRBY rather than a
     * Lua script so the implementation stays readable. The window where a
     * concurrent reader could see a negative balance is tolerated because
     * the mock is single-process in tests and manual dev use.
     */
    public function debitAccount(string $providerId, string $accountRef, int $amountMinor): int
    {
        if ($amountMinor < 0) {
            throw new InvalidArgumentException('Debit amount must be non-negative.');
        }

        $key = $this->accountKey($providerId, $accountRef);

        if (! Redis::exists($key)) {
            $this->accountOrSeed($providerId, $accountRef);
        }

        $next = (int) Redis::hincrby($key, 'balance_minor', -$amountMinor);

        if ($next < 0) {
            $rolledBack = (int) Redis::hincrby($key, 'balance_minor', $amountMinor);

            throw new InsufficientMockBalanceException(
                $providerId,
                $accountRef,
                $rolledBack,
                $amountMinor,
            );
        }

        Redis::expire($key, self::TTL_SECONDS);

        return $next;
    }

    /**
     * Persist a request record (collect or disburse) and append history.
     *
     * @param  array<string, mixed>  $payload
     */
    public function putRequest(
        string $providerId,
        string $kind,
        string $requestId,
        array $payload,
    ): void {
        $this->assertKind($kind);

        Redis::setex(
            $this->requestKey($providerId, $kind, $requestId),
            self::TTL_SECONDS,
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        if (isset($payload['account_ref']) && is_string($payload['account_ref'])) {
            $historyKey = $this->historyKey($providerId, $payload['account_ref']);
            $entry = json_encode([
                'kind'       => $kind,
                'request_id' => $requestId,
                'at'         => time(),
                'payload'    => $payload,
            ], JSON_THROW_ON_ERROR);

            Redis::lpush($historyKey, $entry);
            Redis::ltrim($historyKey, 0, self::HISTORY_LIMIT - 1);
            Redis::expire($historyKey, self::TTL_SECONDS);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRequest(string $providerId, string $kind, string $requestId): ?array
    {
        $this->assertKind($kind);

        $raw = Redis::get($this->requestKey($providerId, $kind, $requestId));

        if ($raw === null) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateRequest(
        string $providerId,
        string $kind,
        string $requestId,
        array $payload,
    ): void {
        $existing = $this->getRequest($providerId, $kind, $requestId);

        if ($existing === null) {
            $this->putRequest($providerId, $kind, $requestId, $payload);

            return;
        }

        Redis::setex(
            $this->requestKey($providerId, $kind, $requestId),
            self::TTL_SECONDS,
            json_encode(array_merge($existing, $payload), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function cacheIdempotent(
        string $providerId,
        string $idempotencyKey,
        array $response,
        int $ttl = self::TTL_SECONDS,
    ): bool {
        $key = $this->idempotencyKey($providerId, $idempotencyKey);
        $encoded = json_encode($response, JSON_THROW_ON_ERROR);

        $created = (bool) Redis::setnx($key, $encoded);

        if ($created) {
            Redis::expire($key, $ttl);
        }

        return $created;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getIdempotent(string $providerId, string $idempotencyKey): ?array
    {
        $raw = Redis::get($this->idempotencyKey($providerId, $idempotencyKey));

        if ($raw === null) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentHistory(string $providerId, string $accountRef, int $limit = 20): array
    {
        $limit = max(1, min($limit, self::HISTORY_LIMIT));

        /** @var array<int, string> $raw */
        $raw = Redis::lrange($this->historyKey($providerId, $accountRef), 0, $limit - 1);

        $entries = [];

        foreach ($raw as $item) {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($item, true);

            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    private function assertKind(string $kind): void
    {
        if ($kind !== 'collect' && $kind !== 'disburse') {
            throw new InvalidArgumentException("Unknown mock request kind: {$kind}");
        }
    }

    private function accountKey(string $providerId, string $accountRef): string
    {
        return "mock:wallet:{$providerId}:account:{$accountRef}";
    }

    private function requestKey(string $providerId, string $kind, string $requestId): string
    {
        return "mock:wallet:{$providerId}:{$kind}:{$requestId}";
    }

    private function idempotencyKey(string $providerId, string $idempotencyKey): string
    {
        return "mock:wallet:{$providerId}:idem:{$idempotencyKey}";
    }

    private function historyKey(string $providerId, string $accountRef): string
    {
        return "mock:wallet:{$providerId}:history:{$accountRef}";
    }

    /**
     * @param  array<int|string, mixed>  $record
     * @return array{balance_minor: int, currency: string, status: string, created_at: int}
     */
    private function hydrateAccount(array $record): array
    {
        return [
            'balance_minor' => (int) ($record['balance_minor'] ?? 0),
            'currency'      => (string) ($record['currency'] ?? 'SZL'),
            'status'        => (string) ($record['status'] ?? 'active'),
            'created_at'    => (int) ($record['created_at'] ?? time()),
        ];
    }
}
