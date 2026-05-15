<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\MtnMomo\Services\MtnMomoCollectionSettler;
use App\Domain\Shared\Money\MoneyConverter;
use App\Models\MtnMomoTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class MoneySettlerService
{
    public function __construct(
        private readonly MtnMomoCollectionSettler $mtnCollectionSettler,
        private readonly WalletOperationsService $walletOps,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function settle(string $providerId, string $providerRequestId, string $outcome, array $payload): void
    {
        if ($providerId !== 'mtn_momo') {
            return;
        }

        $txn = MtnMomoTransaction::query()
            ->where('mtn_reference_id', $providerRequestId)
            ->first();

        if ($txn === null) {
            Log::warning('Wallet provider webhook received for unknown request', [
                'provider_id'         => $providerId,
                'provider_request_id' => $providerRequestId,
            ]);

            return;
        }

        $status = MtnMomoTransaction::normaliseRemoteStatus($outcome);

        if (! $this->recordTerminalCallback($providerRequestId, $status, $payload)) {
            return;
        }

        $txn->update([
            'last_mtn_status'              => $outcome,
            'status'                       => $status,
            'mtn_financial_transaction_id' => $this->stringOrNull($payload['financialTransactionId'] ?? null)
                ?? $this->stringOrNull($payload['financial_transaction_id'] ?? null)
                ?? $txn->mtn_financial_transaction_id,
        ]);

        $fresh = $txn->fresh();
        if ($fresh === null) {
            return;
        }

        if ($fresh->type === MtnMomoTransaction::TYPE_REQUEST_TO_PAY && $status === MtnMomoTransaction::STATUS_SUCCESSFUL) {
            $user = $fresh->user;
            if ($user !== null) {
                $this->mtnCollectionSettler->creditIfNeeded($fresh, $user);
            }

            return;
        }

        if ($fresh->type === MtnMomoTransaction::TYPE_DISBURSEMENT && $status === MtnMomoTransaction::STATUS_FAILED) {
            $this->refundFailedDisbursement($fresh);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordTerminalCallback(string $providerRequestId, string $status, array $payload): bool
    {
        if (! in_array($status, [MtnMomoTransaction::STATUS_SUCCESSFUL, MtnMomoTransaction::STATUS_FAILED], true)) {
            return true;
        }

        try {
            DB::table('mtn_callback_log')->insert([
                'mtn_reference_id' => $providerRequestId,
                'terminal_status'  => $status,
                'body_sha256'      => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                'received_at'      => now(),
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    private function refundFailedDisbursement(MtnMomoTransaction $txn): void
    {
        DB::transaction(function () use ($txn): void {
            /** @var MtnMomoTransaction|null $locked */
            $locked = MtnMomoTransaction::query()
                ->whereKey($txn->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->wallet_debited_at === null || $locked->wallet_refunded_at !== null) {
                return;
            }

            $user = $locked->user;
            if ($user === null) {
                return;
            }

            $account = Account::query()
                ->where('user_uuid', $user->uuid)
                ->orderBy('id')
                ->first();

            $asset = Asset::query()->where('code', $locked->currency)->first();

            if ($account === null || $asset === null) {
                return;
            }

            $amountMinor = MoneyConverter::forAsset($locked->amount, $asset);
            $this->walletOps->deposit(
                $account->uuid,
                $locked->currency,
                (string) $amountMinor,
                'mtn-disburse-refund:' . $locked->mtn_reference_id,
                ['mtn_momo_transaction_id' => $locked->id],
            );

            $locked->update(['wallet_refunded_at' => now()]);
        });
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = (string) $value;

        return $string === '' ? null : $string;
    }
}
