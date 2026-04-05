<?php

declare(strict_types=1);

namespace App\Domain\Mobile\Services;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Exceptions\NotEnoughFunds;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Aggregates\AssetTransactionAggregate;
use App\Domain\Asset\Models\Asset;
use App\Domain\Mobile\Models\Pocket;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PocketTransferService
{
    private const ASSET_CODE = 'SZL';

    /**
     * Move wallet funds into a savings pocket (wallet debit + pocket credit).
     *
     * Uses {@see AssetTransactionAggregate} directly (same ledger path as asset withdraw activities)
     * so balances and projections stay consistent without depending on workflow dispatch timing.
     *
     * @throws NotEnoughFunds When the user wallet balance is insufficient.
     * @throws InvalidArgumentException When the pocket is invalid/locked/completed.
     */
    public function transferToPocket(User $user, Pocket $pocket, float $amountMajor): Pocket
    {
        if ($pocket->is_completed) {
            throw new InvalidArgumentException('Pocket has already reached its target');
        }

        $amountMajorRounded = (float) number_format($amountMajor, 2, '.', '');

        if ($amountMajorRounded <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero');
        }

        return DB::transaction(function () use ($user, $pocket, $amountMajorRounded): Pocket {
            $lockedPocket = Pocket::query()
                ->where('uuid', $pocket->uuid)
                ->where('user_uuid', $user->uuid)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPocket->is_completed) {
                throw new InvalidArgumentException('Pocket has already reached its target');
            }

            $account = Account::query()
                ->where('user_uuid', $user->uuid)
                ->orderBy('id')
                ->first();

            if (! $account) {
                throw new InvalidArgumentException('Wallet account not found');
            }

            $assetCode = (string) config('banking.default_currency', self::ASSET_CODE);
            $asset = Asset::query()->where('code', $assetCode)->first();

            if (! $asset) {
                throw new InvalidArgumentException('Unsupported wallet currency');
            }

            $amountMinor = $asset->toSmallestUnit($amountMajorRounded);

            $accountBalance = AccountBalance::query()
                ->where('account_uuid', $account->uuid)
                ->where('asset_code', $assetCode)
                ->lockForUpdate()
                ->first();

            if (! $accountBalance || ! $accountBalance->hasSufficientBalance($amountMinor)) {
                throw new NotEnoughFunds('Insufficient wallet balance.');
            }

            $transactionId = (string) Str::uuid();

            AssetTransactionAggregate::retrieve($transactionId)
                ->debit(
                    accountUuid: new AccountUuid($account->uuid),
                    assetCode: $assetCode,
                    money: new Money($amountMinor),
                    description: "Savings pocket: {$lockedPocket->name}",
                    metadata: [
                        'source'      => 'pocket_transfer',
                        'pocket_uuid' => $lockedPocket->uuid,
                        'direction'   => 'to_pocket',
                    ]
                )
                ->persist();

            $lockedPocket->addFunds($amountMajorRounded);

            Cache::forget("maphapay.dashboard.balance.{$user->id}");

            return $lockedPocket->fresh() ?? $lockedPocket;
        });
    }

    /**
     * Move pocket funds back into the wallet (pocket debit + wallet credit).
     *
     * @throws InvalidArgumentException When the pocket is locked or insufficient.
     */
    public function transferFromPocket(User $user, Pocket $pocket, float $amountMajor): Pocket
    {
        $amountMajorRounded = (float) number_format($amountMajor, 2, '.', '');

        if ($amountMajorRounded <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero');
        }

        return DB::transaction(function () use ($user, $pocket, $amountMajorRounded): Pocket {
            $lockedPocket = Pocket::query()
                ->where('uuid', $pocket->uuid)
                ->where('user_uuid', $user->uuid)
                ->lockForUpdate()
                ->firstOrFail()
                ->loadMissing('smartRule');

            $smartRule = $lockedPocket->smartRule;
            if ($smartRule?->lock_pocket) {
                throw new InvalidArgumentException('Pocket is locked. Unlock it first to withdraw funds.');
            }

            if (bccomp((string) $lockedPocket->current_amount, (string) $amountMajorRounded, 2) < 0) {
                throw new InvalidArgumentException('Insufficient funds in pocket');
            }

            $account = Account::query()
                ->where('user_uuid', $user->uuid)
                ->orderBy('id')
                ->first();

            if (! $account) {
                throw new InvalidArgumentException('Wallet account not found');
            }

            $assetCode = (string) config('banking.default_currency', self::ASSET_CODE);
            $asset = Asset::query()->where('code', $assetCode)->first();

            if (! $asset) {
                throw new InvalidArgumentException('Unsupported wallet currency');
            }

            $amountMinor = $asset->toSmallestUnit($amountMajorRounded);

            $lockedPocket->withdrawFunds($amountMajorRounded);

            $transactionId = (string) Str::uuid();

            AssetTransactionAggregate::retrieve($transactionId)
                ->credit(
                    accountUuid: new AccountUuid($account->uuid),
                    assetCode: $assetCode,
                    money: new Money($amountMinor),
                    description: "Savings pocket: {$lockedPocket->name}",
                    metadata: [
                        'source'      => 'pocket_transfer',
                        'pocket_uuid' => $lockedPocket->uuid,
                        'direction'   => 'from_pocket',
                    ]
                )
                ->persist();

            Cache::forget("maphapay.dashboard.balance.{$user->id}");

            return $lockedPocket->fresh() ?? $lockedPocket;
        });
    }
}
