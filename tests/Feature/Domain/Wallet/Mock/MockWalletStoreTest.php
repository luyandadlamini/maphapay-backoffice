<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Wallet\Mock;

use App\Domain\Wallet\Mock\Exceptions\InsufficientMockBalanceException;
use App\Domain\Wallet\Mock\MockWalletStore;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Tests\TestCase;

final class MockWalletStoreTest extends TestCase
{
    private MockWalletStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('wallet_mocks.providers.mtn_momo.default_seed_balance_minor', 5_000_000);
        config()->set('wallet_mocks.providers.mtn_momo.currency', 'SZL');

        $this->store = new MockWalletStore();

        $keys = Redis::keys('mock:wallet:*');
        if (is_array($keys) && $keys !== []) {
            $clean = $this->stripPrefixes($keys);
            Redis::del(...$clean);
        }
    }

    public function test_account_or_seed_creates_account_with_configured_balance(): void
    {
        $record = $this->store->accountOrSeed('mtn_momo', '26876000001');

        $this->assertSame(5_000_000, $record['balance_minor']);
        $this->assertSame('SZL', $record['currency']);
        $this->assertSame('active', $record['status']);
        $this->assertGreaterThan(0, $record['created_at']);
    }

    public function test_account_or_seed_returns_existing_account_unchanged_on_second_call(): void
    {
        $first = $this->store->accountOrSeed('mtn_momo', '26876000001');
        $this->store->creditAccount('mtn_momo', '26876000001', 1_000_000);

        $second = $this->store->accountOrSeed('mtn_momo', '26876000001');

        $this->assertSame(6_000_000, $second['balance_minor']);
        $this->assertSame($first['created_at'], $second['created_at']);
    }

    public function test_credit_increments_balance(): void
    {
        $this->store->accountOrSeed('mtn_momo', '26876000001');

        $new = $this->store->creditAccount('mtn_momo', '26876000001', 250_000);

        $this->assertSame(5_250_000, $new);
        $this->assertSame(5_250_000, $this->store->getBalance('mtn_momo', '26876000001'));
    }

    public function test_debit_decrements_balance(): void
    {
        $this->store->accountOrSeed('mtn_momo', '26876000001');

        $new = $this->store->debitAccount('mtn_momo', '26876000001', 1_000_000);

        $this->assertSame(4_000_000, $new);
        $this->assertSame(4_000_000, $this->store->getBalance('mtn_momo', '26876000001'));
    }

    public function test_debit_throws_when_overdraft_and_rolls_back(): void
    {
        $this->store->setBalance('mtn_momo', '26876000001', 100, 'SZL');

        try {
            $this->store->debitAccount('mtn_momo', '26876000001', 250);
            $this->fail('Expected InsufficientMockBalanceException');
        } catch (InsufficientMockBalanceException $e) {
            $this->assertSame('mtn_momo', $e->providerId);
            $this->assertSame('26876000001', $e->accountRef);
            $this->assertSame(100, $e->balanceMinor);
            $this->assertSame(250, $e->debitAmountMinor);
        }

        $this->assertSame(100, $this->store->getBalance('mtn_momo', '26876000001'));
    }

    public function test_set_balance_overwrites_and_persists_currency(): void
    {
        $this->store->setBalance('mtn_momo', '26876000001', 12_345, 'USD');

        $this->assertSame(12_345, $this->store->getBalance('mtn_momo', '26876000001'));

        $record = $this->store->accountOrSeed('mtn_momo', '26876000001');
        $this->assertSame('USD', $record['currency']);
    }

    public function test_put_and_get_request_round_trip(): void
    {
        $this->store->putRequest('mtn_momo', 'collect', 'req-1', [
            'account_ref' => '26876000001',
            'amount'      => '100.00',
            'status'      => 'PENDING',
        ]);

        $loaded = $this->store->getRequest('mtn_momo', 'collect', 'req-1');

        $this->assertSame('PENDING', $loaded['status'] ?? null);
        $this->assertSame('100.00', $loaded['amount'] ?? null);
    }

    public function test_update_request_merges_payload(): void
    {
        $this->store->putRequest('mtn_momo', 'collect', 'req-1', [
            'account_ref' => '26876000001',
            'status'      => 'PENDING',
        ]);

        $this->store->updateRequest('mtn_momo', 'collect', 'req-1', [
            'status'                   => 'SUCCESSFUL',
            'financial_transaction_id' => 'fin-xyz',
        ]);

        $loaded = $this->store->getRequest('mtn_momo', 'collect', 'req-1');

        $this->assertSame('SUCCESSFUL', $loaded['status']);
        $this->assertSame('fin-xyz', $loaded['financial_transaction_id']);
        $this->assertSame('26876000001', $loaded['account_ref']);
    }

    public function test_get_request_returns_null_when_absent(): void
    {
        $this->assertNull($this->store->getRequest('mtn_momo', 'collect', 'missing'));
    }

    public function test_request_kind_must_be_collect_or_disburse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store->putRequest('mtn_momo', 'bogus', 'req-1', []);
    }

    public function test_cache_idempotent_returns_true_first_then_false(): void
    {
        $firstResult = $this->store->cacheIdempotent('mtn_momo', 'key-1', ['ok' => true]);
        $secondResult = $this->store->cacheIdempotent('mtn_momo', 'key-1', ['ok' => 'override-attempt']);

        $this->assertTrue($firstResult);
        $this->assertFalse($secondResult);

        $cached = $this->store->getIdempotent('mtn_momo', 'key-1');
        $this->assertSame(['ok' => true], $cached);
    }

    public function test_recent_history_returns_entries_newest_first(): void
    {
        $this->store->putRequest('mtn_momo', 'collect', 'r1', [
            'account_ref' => '26876000001', 'amount' => '10.00',
        ]);
        $this->store->putRequest('mtn_momo', 'disburse', 'r2', [
            'account_ref' => '26876000001', 'amount' => '20.00',
        ]);

        $history = $this->store->recentHistory('mtn_momo', '26876000001', 10);

        $this->assertCount(2, $history);
        $this->assertSame('disburse', $history[0]['kind']);
        $this->assertSame('collect', $history[1]['kind']);
    }

    public function test_credit_rejects_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->store->creditAccount('mtn_momo', '26876000001', -1);
    }

    public function test_debit_rejects_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->store->debitAccount('mtn_momo', '26876000001', -1);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function stripPrefixes(array $keys): array
    {
        $config = config('database.redis.options.prefix');
        $prefix = is_string($config) ? $config : '';

        if ($prefix === '') {
            return $keys;
        }

        return array_map(
            static fn (string $k): string => str_starts_with($k, $prefix) ? substr($k, strlen($prefix)) : $k,
            $keys,
        );
    }
}
