<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Providers\FnbEwallet;

use App\Domain\Account\Models\Account;
use App\Domain\Wallet\Contracts\ProviderSettler;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Services\WalletOperationsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class FnbEwalletSettler implements ProviderSettler
{
    public function __construct(
        private readonly WalletOperationsService $walletOps,
    ) {
    }

    public function providerId(): string
    {
        return 'fnb_ewallet';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function settle(string $providerRequestId, string $outcome, array $payload): void
    {
        $status = $this->normaliseStatus($outcome);

        DB::transaction(function () use ($providerRequestId, $status, $outcome, $payload): void {
            /** @var WalletProviderTransaction|null $row */
            $row = WalletProviderTransaction::query()
                ->where('provider_id', $this->providerId())
                ->where('provider_request_id', $providerRequestId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                Log::warning('FNB eWallet callback for unknown wallet_provider_transaction', [
                    'provider_request_id' => $providerRequestId,
                ]);

                return;
            }

            if ($row->status === WalletProviderTransaction::STATUS_SUCCESSFUL
                || $row->status === WalletProviderTransaction::STATUS_FAILED) {
                return;
            }

            $row->update([
                'status'     => $status,
                'payload'    => array_merge((array) $row->payload, ['remote_status' => $outcome, 'callback' => $payload]),
                'settled_at' => $status === WalletProviderTransaction::STATUS_PENDING ? null : now(),
            ]);

            if ($status === WalletProviderTransaction::STATUS_SUCCESSFUL
                && $row->type === WalletProviderTransaction::TYPE_COLLECT) {
                $this->creditUser($row);
            }
        });
    }

    private function creditUser(WalletProviderTransaction $row): void
    {
        if ($row->user_uuid === null || $row->user_uuid === '') {
            return;
        }

        $account = Account::query()
            ->where('user_uuid', $row->user_uuid)
            ->orderBy('id')
            ->first();

        if ($account === null) {
            Log::warning('FNB eWallet credit skipped: no account for user', [
                'user_uuid'           => $row->user_uuid,
                'provider_request_id' => $row->provider_request_id,
            ]);

            return;
        }

        $this->walletOps->deposit(
            $account->uuid,
            $row->currency,
            (string) $row->amount_minor,
            'fnb-ewallet-credit:' . $row->provider_request_id,
            ['wallet_provider_transaction_id' => $row->id],
        );
    }

    private function normaliseStatus(string $remote): string
    {
        $u = strtoupper(trim($remote));

        return match (true) {
            in_array($u, ['SUCCESSFUL', 'SUCCESS', 'COMPLETED', 'POSTED'], true) => WalletProviderTransaction::STATUS_SUCCESSFUL,
            in_array($u, ['FAILED', 'REJECTED', 'DECLINED'], true)               => WalletProviderTransaction::STATUS_FAILED,
            default                                                              => WalletProviderTransaction::STATUS_PENDING,
        };
    }
}
