<?php

declare(strict_types=1);

namespace App\Domain\MtnMomo\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent wallet credit when an MTN collection (request-to-pay) succeeds.
 */
final class MtnMomoCollectionSettler
{
    public function __construct(
        private readonly WalletOperationsService $walletOps,
    ) {
    }

    public function creditIfNeeded(MtnMomoTransaction $txn, User $user): void
    {
        if ($txn->type !== MtnMomoTransaction::TYPE_REQUEST_TO_PAY) {
            return;
        }

        if ($txn->status !== MtnMomoTransaction::STATUS_SUCCESSFUL) {
            return;
        }

        DB::transaction(function () use ($txn, $user): void {
            /** @var MtnMomoTransaction|null $locked */
            $locked = MtnMomoTransaction::query()->whereKey($txn->id)->lockForUpdate()->first();

            if ($locked === null || $locked->wallet_credited_at !== null) {
                return;
            }

            $account = Account::query()
                ->where('user_uuid', $user->uuid)
                ->orderBy('id')
                ->first();

            if (! $account) {
                return;
            }

            $asset = Asset::query()->where('code', $locked->currency)->first();

            if (! $asset) {
                return;
            }

            $minor = (string) MoneyConverter::forAsset($locked->amount, $asset);

            $this->walletOps->deposit(
                $account->uuid,
                $locked->currency,
                $minor,
                'mtn-collection:' . ($locked->mtn_reference_id ?? $locked->id),
                [
                    'mtn_momo_transaction_id' => $locked->id,
                ],
            );

            $locked->forceFill(['wallet_credited_at' => now()])->save();
        });
    }
}
