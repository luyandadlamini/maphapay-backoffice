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
use App\Domain\Wallet\Events\Broadcast\WalletBalanceUpdated;
use Illuminate\Support\Facades\Cache;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class AssetBalanceProjector extends Projector
{
    /**
     * Handle asset balance addition events.
     */
    public function onAssetBalanceAdded(AssetBalanceAdded $event): void
    {
        app(CreditAssetBalance::class)($event);

        // Invalidate cache after balance update
        if ($account = Account::where('uuid', $event->aggregateRootUuid())->first()) {
            $this->refreshAccountReadModels($account);
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
            $this->refreshAccountReadModels($account);
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
                $this->refreshAccountReadModels($account);
            }
        }
    }

    private function refreshAccountReadModels(Account $account): void
    {
        app(CacheManager::class)->onAccountUpdated($account);

        if ($account->user !== null) {
            Cache::forget("maphapay.dashboard.balance.{$account->user->id}");
            WalletBalanceUpdated::dispatch($account->user->id);
        }
    }
}
