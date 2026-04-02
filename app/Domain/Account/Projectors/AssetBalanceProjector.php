<?php

namespace App\Domain\Account\Projectors;

use App\Domain\Account\Actions\CreditAssetBalance;
use App\Domain\Account\Actions\DebitAssetBalance;
use App\Domain\Account\Events\AssetBalanceAdded;
use App\Domain\Account\Events\AssetBalanceSubtracted;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Services\Cache\CacheManager;
use App\Domain\Asset\Events\AssetTransferCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class AssetBalanceProjector extends Projector implements ShouldQueue
{
    /**
     * Handle asset balance addition events.
     */
    public function onAssetBalanceAdded(AssetBalanceAdded $event): void
    {
        app(CreditAssetBalance::class)($event);

        // Invalidate cache after balance update
        if ($account = Account::where('uuid', $event->aggregateRootUuid())->first()) {
            app(CacheManager::class)->onAccountUpdated($account);
        }
    }

    /**
     * Handle asset balance subtraction events.
     */
    public function onAssetBalanceSubtracted(AssetBalanceSubtracted $event): void
    {
        app(DebitAssetBalance::class)($event);

        // Invalidate cache after balance update
        if ($account = Account::where('uuid', $event->aggregateRootUuid())->first()) {
            app(CacheManager::class)->onAccountUpdated($account);
        }
    }

    /**
     * Handle asset transfer completed events to update balances of both parties.
     */
    public function onAssetTransferCompleted(AssetTransferCompleted $event): void
    {
        // 1. Debit from source account
        $fromBalance = AccountBalance::firstOrCreate(
            [
                'account_uuid' => (string) $event->fromAccountUuid,
                'asset_code'   => $event->fromAssetCode,
            ],
            ['balance' => 0]
        );
        $fromBalance->balance -= $event->fromAmount->getAmount();
        $fromBalance->save();

        // 2. Credit to destination account
        $toBalance = AccountBalance::firstOrCreate(
            [
                'account_uuid' => (string) $event->toAccountUuid,
                'asset_code'   => $event->toAssetCode,
            ],
            ['balance' => 0]
        );
        $toBalance->balance += $event->toAmount->getAmount();
        $toBalance->save();

        // 3. Invalidate caches for both accounts
        foreach ([$event->fromAccountUuid, $event->toAccountUuid] as $uuid) {
            if ($account = Account::where('uuid', (string) $uuid)->first()) {
                app(CacheManager::class)->onAccountUpdated($account);
            }
        }
    }
}
