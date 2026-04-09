<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Services\Cache\CacheManager;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Ledger\Enums\LedgerPostingStatus;
use App\Domain\Ledger\Enums\LedgerPostingType;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Models\LedgerPosting;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Events\Broadcast\WalletBalanceUpdated;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

class LedgerPostingService
{
    /**
     * @param  array<string, mixed>  $handlerResult
     * @return array<string, mixed>|null
     */
    public function createForAuthorizedTransaction(AuthorizedTransaction $transaction, array $handlerResult): ?array
    {
        $postingType = match ($transaction->remark) {
            AuthorizedTransaction::REMARK_SEND_MONEY             => LedgerPostingType::SEND_MONEY,
            AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED => LedgerPostingType::REQUEST_MONEY_ACCEPT,
            default                                              => null,
        };

        if ($postingType === null) {
            return null;
        }

        $payload = is_array($transaction->payload) ? $transaction->payload : [];
        $assetCode = (string) ($handlerResult['asset_code'] ?? $payload['asset_code'] ?? '');
        $amount = (string) ($handlerResult['amount'] ?? $payload['amount'] ?? '');
        $fromAccountUuid = Arr::get($payload, 'from_account_uuid');
        $toAccountUuid = Arr::get($payload, 'to_account_uuid');
        $reference = (string) ($handlerResult['reference'] ?? $payload['reference'] ?? $transaction->trx);

        if ($assetCode === '' || $amount === '' || ! is_string($fromAccountUuid) || ! is_string($toAccountUuid)) {
            throw new InvalidArgumentException('Posting requires asset, amount, and both account UUIDs.');
        }

        $asset = Asset::query()->where('code', $assetCode)->first();
        if ($asset === null) {
            throw new RuntimeException(sprintf('Posting asset [%s] could not be resolved.', $assetCode));
        }

        $amountMinor = (int) MoneyConverter::forAsset($amount, $asset);
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Posting amount must be greater than zero.');
        }

        $entries = [
            [
                'account_uuid'  => $fromAccountUuid,
                'asset_code'    => $assetCode,
                'signed_amount' => -$amountMinor,
                'entry_type'    => 'debit',
                'metadata'      => [
                    'role' => 'sender',
                ],
            ],
            [
                'account_uuid'  => $toAccountUuid,
                'asset_code'    => $assetCode,
                'signed_amount' => $amountMinor,
                'entry_type'    => 'credit',
                'metadata'      => [
                    'role' => 'recipient',
                ],
            ],
        ];

        $this->assertEntriesBalanced($entries);

        $posting = LedgerPosting::query()->create([
            'authorized_transaction_id'  => $transaction->id,
            'authorized_transaction_trx' => $transaction->trx,
            'posting_type'               => $postingType->value,
            'status'                     => LedgerPostingStatus::POSTED->value,
            'asset_code'                 => $assetCode,
            'transfer_reference'         => $reference,
            'money_request_id'           => is_string($payload['money_request_id'] ?? null) ? $payload['money_request_id'] : null,
            'rule_version'               => 1,
            'entries_hash'               => hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR)),
            'metadata'                   => [
                'remark'  => $transaction->remark,
                'payload' => [
                    'money_request_id' => $payload['money_request_id'] ?? null,
                ],
            ],
            'posted_at' => now(),
        ]);

        foreach ($entries as $entry) {
            LedgerEntry::query()->create([
                'ledger_posting_id' => $posting->id,
                'account_uuid'      => $entry['account_uuid'],
                'asset_code'        => $entry['asset_code'],
                'signed_amount'     => $entry['signed_amount'],
                'entry_type'        => $entry['entry_type'],
                'metadata'          => $entry['metadata'],
            ]);
        }

        $this->applyAccountBalanceReadModels($entries);

        $posting->load('entries');

        return $this->serializePosting($posting);
    }

    /**
     * @param  list<array{asset_code: string, signed_amount: int}>  $entries
     */
    private function assertEntriesBalanced(array $entries): void
    {
        $balances = [];

        foreach ($entries as $entry) {
            $assetCode = $entry['asset_code'];
            $balances[$assetCode] = ($balances[$assetCode] ?? 0) + $entry['signed_amount'];
        }

        foreach ($balances as $assetCode => $balance) {
            if ($balance !== 0) {
                throw new RuntimeException(sprintf('Posting entries do not balance for asset [%s].', $assetCode));
            }
        }
    }

    /**
     * @param  list<array{account_uuid: string, asset_code: string, signed_amount: int, entry_type: string, metadata: array<string, mixed>}>  $entries
     */
    private function applyAccountBalanceReadModels(array $entries): void
    {
        $touchedAccounts = [];

        foreach ($entries as $entry) {
            $balance = AccountBalance::query()->firstOrCreate(
                [
                    'account_uuid' => $entry['account_uuid'],
                    'asset_code' => $entry['asset_code'],
                ],
                ['balance' => 0],
            );

            $balance->balance += $entry['signed_amount'];
            $balance->save();

            $touchedAccounts[$entry['account_uuid']] = true;
        }

        foreach (array_keys($touchedAccounts) as $accountUuid) {
            $account = Account::query()->where('uuid', $accountUuid)->first();

            if ($account === null) {
                continue;
            }

            $this->refreshAccountReadModels($account);
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

    /**
     * @return array<string, mixed>
     */
    private function serializePosting(LedgerPosting $posting): array
    {
        return [
            'id'                 => $posting->id,
            'posting_type'       => $posting->posting_type,
            'status'             => $posting->status,
            'asset_code'         => $posting->asset_code,
            'transfer_reference' => $posting->transfer_reference,
            'money_request_id'   => $posting->money_request_id,
            'rule_version'       => $posting->rule_version,
            'posted_at'          => $posting->posted_at?->toIso8601String(),
            'entries'            => $posting->entries
                ->map(fn (LedgerEntry $entry): array => [
                    'id'            => $entry->id,
                    'account_uuid'  => $entry->account_uuid,
                    'asset_code'    => $entry->asset_code,
                    'signed_amount' => $entry->signed_amount,
                    'entry_type'    => $entry->entry_type,
                    'metadata'      => $entry->metadata,
                ])
                ->all(),
        ];
    }
}
