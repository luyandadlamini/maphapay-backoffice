<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Services\Cache\CacheManager;
use App\Domain\Account\Support\InternalTransferProjectionWriter;
use App\Domain\Asset\Models\Asset;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Ledger\Enums\LedgerPostingStatus;
use App\Domain\Ledger\Enums\LedgerPostingType;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Models\LedgerPosting;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Events\Broadcast\WalletBalanceUpdated;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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

        $posting = $this->createPostingRecord(
            postingType: $postingType,
            assetCode: $assetCode,
            entries: $entries,
            authorizedTransactionId: $transaction->id,
            authorizedTransactionTrx: $transaction->trx,
            transferReference: $reference,
            moneyRequestId: is_string($payload['money_request_id'] ?? null) ? $payload['money_request_id'] : null,
            metadata: [
                'remark'  => $transaction->remark,
                'payload' => [
                    'money_request_id' => $payload['money_request_id'] ?? null,
                ],
            ],
        );

        $this->applyAccountBalanceReadModels($entries);
        $this->applyTransactionProjectionReadModels($posting, $transaction, $entries);

        $posting->load('entries');

        return $this->serializePosting($posting);
    }

    /**
     * @return array<string, mixed>
     */
    public function createCompensatingReversal(
        LedgerPosting $posting,
        string $reason,
        ?string $authorizedBy = null,
    ): array {
        $posting->loadMissing('entries');

        if ($posting->status !== LedgerPostingStatus::POSTED->value) {
            throw new InvalidArgumentException('Only posted ledger postings can be compensatingly reversed.');
        }

        $entries = array_values($posting->entries
            ->map(function (LedgerEntry $entry): array {
                if (! is_string($entry->account_uuid) || $entry->account_uuid === '') {
                    throw new RuntimeException('Compensating reversal requires non-null account UUIDs on original ledger entries.');
                }

                return [
                    'account_uuid'  => $entry->account_uuid,
                    'asset_code'    => $entry->asset_code,
                    'signed_amount' => $entry->signed_amount * -1,
                    'entry_type'    => $entry->signed_amount > 0 ? 'debit' : 'credit',
                    'metadata'      => array_merge($entry->metadata ?? [], [
                        'reversal_of_entry_id' => $entry->id,
                    ]),
                ];
            })
            ->all());

        $this->assertEntriesBalanced($entries);

        $reversalPosting = $this->createPostingRecord(
            postingType: LedgerPostingType::COMPENSATING_REVERSAL,
            assetCode: $posting->asset_code,
            entries: $entries,
            authorizedTransactionId: null,
            authorizedTransactionTrx: $this->syntheticPostingTrx('REV'),
            transferReference: $posting->transfer_reference,
            moneyRequestId: $posting->money_request_id,
            metadata: array_filter([
                'posting_class'         => LedgerPostingType::COMPENSATING_REVERSAL->value,
                'related_posting_id'    => $posting->id,
                'original_posting_type' => $posting->posting_type,
                'reversal_reason'       => trim($reason),
                'authorized_by'         => $authorizedBy !== null ? trim($authorizedBy) : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );

        $this->applyAccountBalanceReadModels($entries);

        $posting->update([
            'status'   => LedgerPostingStatus::REVERSED->value,
            'metadata' => array_merge($posting->metadata ?? [], array_filter([
                'reversed_by_posting_id' => $reversalPosting->id,
                'reversal_reason'        => trim($reason),
                'reversed_by'            => $authorizedBy !== null ? trim($authorizedBy) : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')),
        ]);

        $reversalPosting->load('entries');

        return $this->serializePosting($reversalPosting);
    }

    /**
     * @return array<string, mixed>
     */
    public function createManualAdjustment(
        string $accountUuid,
        string $contraAccountUuid,
        string $assetCode,
        string $amount,
        string $direction,
        string $reason,
        ?string $authorizedBy = null,
    ): array {
        return $this->createAdjustmentPosting(
            postingType: LedgerPostingType::MANUAL_ADJUSTMENT,
            relatedPosting: null,
            accountUuid: $accountUuid,
            contraAccountUuid: $contraAccountUuid,
            assetCode: $assetCode,
            amount: $amount,
            direction: $direction,
            reason: $reason,
            authorizedBy: $authorizedBy,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function createReconciliationAdjustment(
        LedgerPosting $posting,
        string $accountUuid,
        string $contraAccountUuid,
        string $amount,
        string $direction,
        string $reason,
        ?string $authorizedBy = null,
    ): array {
        return $this->createAdjustmentPosting(
            postingType: LedgerPostingType::RECONCILIATION_ADJUSTMENT,
            relatedPosting: $posting,
            accountUuid: $accountUuid,
            contraAccountUuid: $contraAccountUuid,
            assetCode: $posting->asset_code,
            amount: $amount,
            direction: $direction,
            reason: $reason,
            authorizedBy: $authorizedBy,
        );
    }

    /**
     * @param  list<array{account_uuid: string, asset_code: string, signed_amount: int, entry_type: string, metadata: array<string, mixed>}>  $entries
     */
    private function applyTransactionProjectionReadModels(
        LedgerPosting $posting,
        AuthorizedTransaction $transaction,
        array $entries,
    ): void {
        if (count($entries) !== 2) {
            return;
        }

        $subtype = match ($transaction->remark) {
            AuthorizedTransaction::REMARK_SEND_MONEY             => 'send_money',
            AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED => 'request_money_accept',
            default                                              => null,
        };

        if ($subtype === null) {
            return;
        }

        $debitEntry = collect($entries)->firstWhere('entry_type', 'debit');
        $creditEntry = collect($entries)->firstWhere('entry_type', 'credit');

        if (! is_array($debitEntry) || ! is_array($creditEntry)) {
            return;
        }

        $payload = is_array($transaction->payload) ? $transaction->payload : [];
        $note = is_string($payload['note'] ?? null) ? trim((string) $payload['note']) : null;
        $note = $note === '' ? null : $note;

        app(InternalTransferProjectionWriter::class)->create(
            fromAccountUuid: $debitEntry['account_uuid'],
            toAccountUuid: $creditEntry['account_uuid'],
            fromAssetCode: $debitEntry['asset_code'],
            toAssetCode: $creditEntry['asset_code'],
            fromAmount: abs($debitEntry['signed_amount']),
            toAmount: abs($creditEntry['signed_amount']),
            subtype: $subtype,
            description: null,
            reference: $posting->transfer_reference,
            metadata: array_filter([
                'event_type'         => 'LedgerPostingCreated',
                'event_uuid'         => $posting->id,
                'source'             => 'p2p',
                'operation_type'     => $subtype,
                'money_state_anchor' => 'ledger_posting',
                'note'               => $note,
                'money_request_id'   => $posting->money_request_id,
                'p2p_display'        => [
                    'sender_label'    => $this->accountOwnerLabel($debitEntry['account_uuid']),
                    'recipient_label' => $this->accountOwnerLabel($creditEntry['account_uuid']),
                    'note_preview'    => $note,
                ],
                'ledger_posting_id'         => $posting->id,
                'ledger_posting_status'     => $posting->status,
                'ledger_transfer_reference' => $posting->transfer_reference,
                'projection_anchor'         => 'ledger_posting',
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
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
     * @param  list<array{account_uuid: string|null, asset_code: string, signed_amount: int, entry_type: string, metadata: array<string, mixed>}>  $entries
     * @param  array<string, mixed>  $metadata
     */
    private function createPostingRecord(
        LedgerPostingType $postingType,
        string $assetCode,
        array $entries,
        ?string $authorizedTransactionId,
        string $authorizedTransactionTrx,
        ?string $transferReference,
        ?string $moneyRequestId,
        array $metadata,
    ): LedgerPosting {
        $posting = LedgerPosting::query()->create([
            'authorized_transaction_id'  => $authorizedTransactionId,
            'authorized_transaction_trx' => $authorizedTransactionTrx,
            'posting_type'               => $postingType->value,
            'status'                     => LedgerPostingStatus::POSTED->value,
            'asset_code'                 => $assetCode,
            'transfer_reference'         => $transferReference,
            'money_request_id'           => $moneyRequestId,
            'rule_version'               => 1,
            'entries_hash'               => hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR)),
            'metadata'                   => $metadata,
            'posted_at'                  => now(),
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

        return $posting;
    }

    private function syntheticPostingTrx(string $prefix): string
    {
        return substr($prefix . '-' . Str::upper(Str::random(40)), 0, 32);
    }

    /**
     * @return array<string, mixed>
     */
    private function createAdjustmentPosting(
        LedgerPostingType $postingType,
        ?LedgerPosting $relatedPosting,
        string $accountUuid,
        string $contraAccountUuid,
        string $assetCode,
        string $amount,
        string $direction,
        string $reason,
        ?string $authorizedBy = null,
    ): array {
        $direction = trim($direction);
        if (! in_array($direction, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException('Adjustment direction must be credit or debit.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Adjustment reason is required.');
        }

        $asset = Asset::query()->where('code', $assetCode)->first();
        if ($asset === null) {
            throw new RuntimeException(sprintf('Posting asset [%s] could not be resolved.', $assetCode));
        }

        $amountMinor = (int) MoneyConverter::forAsset($amount, $asset);
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Adjustment amount must be greater than zero.');
        }

        $targetSignedAmount = $direction === 'credit' ? $amountMinor : -$amountMinor;
        $contraSignedAmount = $targetSignedAmount * -1;

        $entries = [
            [
                'account_uuid'  => $accountUuid,
                'asset_code'    => $assetCode,
                'signed_amount' => $targetSignedAmount,
                'entry_type'    => $targetSignedAmount > 0 ? 'credit' : 'debit',
                'metadata'      => [
                    'role' => 'adjusted_account',
                ],
            ],
            [
                'account_uuid'  => $contraAccountUuid,
                'asset_code'    => $assetCode,
                'signed_amount' => $contraSignedAmount,
                'entry_type'    => $contraSignedAmount > 0 ? 'credit' : 'debit',
                'metadata'      => [
                    'role' => 'contra_account',
                ],
            ],
        ];

        $this->assertEntriesBalanced($entries);

        $adjustmentPosting = $this->createPostingRecord(
            postingType: $postingType,
            assetCode: $assetCode,
            entries: $entries,
            authorizedTransactionId: null,
            authorizedTransactionTrx: $this->syntheticPostingTrx('ADJ'),
            transferReference: $relatedPosting?->transfer_reference,
            moneyRequestId: $relatedPosting?->money_request_id,
            metadata: array_filter([
                'posting_class'        => $postingType->value,
                'related_posting_id'   => $relatedPosting?->id,
                'adjustment_reason'    => $reason,
                'adjustment_direction' => $direction,
                'authorized_by'        => $authorizedBy !== null ? trim($authorizedBy) : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );

        $this->applyAccountBalanceReadModels($entries);

        if ($relatedPosting !== null) {
            $relatedPosting->update([
                'status'   => LedgerPostingStatus::ADJUSTED->value,
                'metadata' => array_merge($relatedPosting->metadata ?? [], array_filter([
                    'adjusted_by_posting_id' => $adjustmentPosting->id,
                    'adjustment_reason'      => $reason,
                    'adjusted_by'            => $authorizedBy !== null ? trim($authorizedBy) : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== '')),
            ]);
        }

        $adjustmentPosting->load('entries');

        return $this->serializePosting($adjustmentPosting);
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
                    'asset_code'   => $entry['asset_code'],
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

    private function accountOwnerLabel(string $accountUuid): string
    {
        /** @var User|null $user */
        $user = Account::query()
            ->with('user')
            ->where('uuid', $accountUuid)
            ->first()
            ?->user;

        return $this->userLabel($user);
    }

    private function userLabel(?User $user): string
    {
        if ($user === null) {
            return 'contact';
        }

        $name = trim((string) ($user->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $username = trim((string) ($user->username ?? ''));
        if ($username !== '') {
            return $username;
        }

        $mobile = trim((string) ($user->mobile ?? ''));
        if ($mobile !== '') {
            return $mobile;
        }

        return 'contact';
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
