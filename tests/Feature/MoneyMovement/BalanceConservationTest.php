<?php

declare(strict_types=1);

namespace Tests\Feature\MoneyMovement;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Hash;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Projectors\AssetBalanceProjector;
use App\Domain\Asset\Events\AssetTransferCompleted;
use App\Domain\Asset\Models\Asset;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\DomainTestCase;

/**
 * Balance Conservation Invariant
 * --------------------------------------------------------------------------
 * The sum of all balances within a tenant must remain CONSTANT across any
 * series of intra-tenant transfers (no provider fees deducted, no external
 * source/sink). If a single transfer changes the total, balance integrity
 * has been violated — either the debit/credit are asymmetric, the projection
 * is targeting the wrong DB, or a transfer is being double-counted.
 *
 * This test would have caught the original savings-pocket projection bug
 * the moment it was introduced.
 */
class BalanceConservationTest extends DomainTestCase
{
    private const NUM_ACCOUNTS = 5;

    private const NUM_TRANSFERS = 50;

    private const STARTING_MINOR_PER_ACCOUNT = 1_000_000; // SZL 10,000.00

    /** @return list<string> */
    private function seedAccountsWithBalances(int $count, int $startingBalanceMinor, string $assetCode): array
    {
        Asset::firstOrCreate(
            ['code' => $assetCode],
            ['name' => 'Conservation Test Asset', 'type' => 'fiat', 'precision' => 2, 'is_active' => true],
        );

        $uuids = [];
        for ($i = 0; $i < $count; $i++) {
            $uuid = (string) Str::uuid();
            $owner = User::factory()->create();
            Account::factory()->forUser($owner)->create(['uuid' => $uuid]);
            AccountBalance::query()->create([
                'account_uuid' => $uuid,
                'asset_code'   => $assetCode,
                'balance'      => $startingBalanceMinor,
            ]);
            $uuids[] = $uuid;
        }

        return $uuids;
    }

    private function sumOfBalances(string $assetCode): int
    {
        return (int) AccountBalance::query()
            ->where('asset_code', $assetCode)
            ->sum('balance');
    }

    #[Test]
    public function intra_tenant_transfers_preserve_total_balance(): void
    {
        $assetCode = 'SZL';
        $accountIds = $this->seedAccountsWithBalances(
            self::NUM_ACCOUNTS,
            self::STARTING_MINOR_PER_ACCOUNT,
            $assetCode,
        );

        $expectedTotal = self::NUM_ACCOUNTS * self::STARTING_MINOR_PER_ACCOUNT;

        // Sanity: starting state is conserved.
        $this->assertSame(
            $expectedTotal,
            $this->sumOfBalances($assetCode),
            'Starting balance total does not match seed — fixture bug.',
        );

        $projector = app(AssetBalanceProjector::class);
        // Deterministic seed for reproducibility — change to e.g. random_int() for fuzz mode.
        mt_srand(20260518);

        for ($i = 0; $i < self::NUM_TRANSFERS; $i++) {
            // Pick two distinct random accounts.
            $fromIdx = mt_rand(0, self::NUM_ACCOUNTS - 1);
            do {
                $toIdx = mt_rand(0, self::NUM_ACCOUNTS - 1);
            } while ($toIdx === $fromIdx);

            $from = $accountIds[$fromIdx];
            $to = $accountIds[$toIdx];

            // Transfer a random amount that does not exceed sender's current balance.
            $senderBalance = (int) AccountBalance::query()
                ->where('account_uuid', $from)->where('asset_code', $assetCode)->value('balance');

            if ($senderBalance <= 0) {
                continue; // sender is broke; skip
            }

            $amount = mt_rand(1, $senderBalance);

            $event = new AssetTransferCompleted(
                fromAccountUuid: AccountUuid::fromString($from),
                toAccountUuid:   AccountUuid::fromString($to),
                fromAssetCode:   $assetCode,
                toAssetCode:     $assetCode,
                fromAmount:      new Money($amount),
                toAmount:        new Money($amount),
                hash:            Hash::fromData("conservation-{$i}"),
                description:     "Conservation transfer #{$i}",
                transferId:      'REF-' . Str::upper(Str::random(10)),
                metadata:        ['source' => 'p2p', 'operation_type' => 'send_money'],
            );

            $projector->onAssetTransferCompleted($event);

            $actualTotal = $this->sumOfBalances($assetCode);
            $this->assertSame(
                $expectedTotal,
                $actualTotal,
                sprintf(
                    'Balance conservation violated after transfer #%d (%s → %s, amount %d). '
                    . 'Expected total %d, got %d (delta %+d). '
                    . 'A projector is writing asymmetric debit/credit or targeting the wrong DB.',
                    $i,
                    $from,
                    $to,
                    $amount,
                    $expectedTotal,
                    $actualTotal,
                    $actualTotal - $expectedTotal,
                ),
            );
        }
    }

    #[Test]
    public function self_transfers_do_not_change_total(): void
    {
        // Edge case: a transfer where from == to (should not be allowed in practice
        // but the projection logic must still preserve conservation regardless).
        $assetCode = 'SZL';
        $accountIds = $this->seedAccountsWithBalances(1, 500_000, $assetCode);
        $only = $accountIds[0];

        $event = new AssetTransferCompleted(
            fromAccountUuid: AccountUuid::fromString($only),
            toAccountUuid:   AccountUuid::fromString($only),
            fromAssetCode:   $assetCode,
            toAssetCode:     $assetCode,
            fromAmount:      new Money(100_000),
            toAmount:        new Money(100_000),
            hash:            Hash::fromData('self-transfer-test'),
        );

        $before = $this->sumOfBalances($assetCode);
        app(AssetBalanceProjector::class)->onAssetTransferCompleted($event);
        $after = $this->sumOfBalances($assetCode);

        $this->assertSame($before, $after, 'Self-transfer must not change total balance.');
    }
}
