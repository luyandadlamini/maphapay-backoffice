<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Domain\Asset\Models\AssetTransfer;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Ledger\Models\LedgerPosting;
use App\Models\MtnMomoTransaction;
use App\Models\MoneyRequest;
use App\Support\Reconciliation\ReconciliationReportDataLoader;
use Illuminate\Support\Collection;

class MoneyMovementTransactionInspector
{
    /**
     * @return array<string, mixed>
     */
    public function inspect(?string $trx = null, ?string $reference = null): array
    {
        $transaction = $this->resolveAuthorizedTransaction($trx, $reference);
        $resolvedReference = $reference
            ?? $transaction?->result['reference']
            ?? $transaction?->payload['reference']
            ?? null;

        $moneyRequestId = $transaction?->payload['money_request_id'] ?? null;
        $moneyRequest = is_string($moneyRequestId) && $moneyRequestId !== ''
            ? MoneyRequest::query()->find($moneyRequestId)
            : ($trx !== null
                ? MoneyRequest::query()->where('trx', $trx)->first()
                : null);

        if ($resolvedReference === null && $moneyRequest?->trx !== null && $transaction !== null) {
            $resolvedReference = $transaction->result['reference'] ?? $transaction->payload['reference'] ?? null;
        }

        $assetTransfer = $resolvedReference !== null
            ? AssetTransfer::query()
                ->where('reference', $resolvedReference)
                ->orWhere('uuid', $resolvedReference)
                ->orWhere('transfer_id', $resolvedReference)
                ->first()
            : null;

        $ledgerPosting = $this->resolveLedgerPosting($transaction, $resolvedReference);

        $projections = $resolvedReference !== null
            ? TransactionProjection::query()
                ->where('reference', $resolvedReference)
                ->orderBy('created_at')
                ->get()
                ->map(fn (TransactionProjection $projection): array => [
                    'uuid'                      => $projection->uuid,
                    'account_uuid'              => $projection->account_uuid,
                    'type'                      => $projection->type,
                    'subtype'                   => $projection->subtype,
                    'status'                    => $projection->status,
                    'amount'                    => $projection->formatted_amount,
                    'asset_code'                => $projection->asset_code,
                    'description'               => $projection->description,
                    'reference'                 => $projection->reference,
                    'ledger_posting_id'         => $projection->metadata['ledger_posting_id'] ?? null,
                    'ledger_posting_status'     => $projection->metadata['ledger_posting_status'] ?? null,
                    'ledger_transfer_reference' => $projection->metadata['ledger_transfer_reference'] ?? null,
                    'projection_anchor'         => $projection->metadata['projection_anchor'] ?? null,
                    'created_at'                => $projection->created_at?->toIso8601String(),
                ])
                ->all()
            : [];

        $projectionState = $this->buildProjectionState(
            transaction: $transaction,
            ledgerPosting: $ledgerPosting,
            assetTransfer: $assetTransfer,
            projectionCount: count($projections),
        );
        $relatedLedgerPostings = $this->resolveRelatedLedgerPostings($ledgerPosting);
        $postingChainIds = array_values(collect([$ledgerPosting?->id])
            ->merge(collect($relatedLedgerPostings)->pluck('id'))
            ->filter(static fn (?string $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all());
        $reconciliationExceptions = $this->resolveReconciliationExceptions($postingChainIds);
        $minorFamilyContext = $this->resolveMinorFamilyContext($resolvedReference);

        $timeline = [];
        if ($transaction !== null) {
            $timeline[] = [
                'event'  => 'authorization_initiated',
                'status' => $transaction->status,
                'trx'    => $transaction->trx,
                'at'     => $transaction->created_at->toIso8601String(),
            ];

            $policy = is_array($transaction->payload['_verification_policy'] ?? null)
                ? $transaction->payload['_verification_policy']
                : null;
            if ($policy !== null) {
                $timeline[] = [
                    'event'               => 'challenge_decision',
                    'verification_policy' => $policy['verification_type'] ?? null,
                    'reason'              => $policy['reason'] ?? null,
                    'risk_reason'         => $policy['risk_reason'] ?? null,
                    'at'                  => $transaction->created_at->toIso8601String(),
                ];
            }

            if ($transaction->status === AuthorizedTransaction::STATUS_COMPLETED) {
                $timeline[] = [
                    'event' => 'verification_succeeded',
                    'trx'   => $transaction->trx,
                    'at'    => $transaction->updated_at->toIso8601String(),
                ];
            } elseif ($transaction->status === AuthorizedTransaction::STATUS_FAILED) {
                $timeline[] = [
                    'event'                 => 'verification_failed',
                    'trx'                   => $transaction->trx,
                    'failure_reason'        => $transaction->failure_reason,
                    'verification_failures' => $transaction->verification_failures,
                    'at'                    => $transaction->updated_at->toIso8601String(),
                ];
            } elseif ($transaction->status === AuthorizedTransaction::STATUS_EXPIRED) {
                $timeline[] = [
                    'event'                 => 'verification_expired',
                    'trx'                   => $transaction->trx,
                    'failure_reason'        => $transaction->failure_reason,
                    'verification_failures' => $transaction->verification_failures,
                    'at'                    => $transaction->updated_at->toIso8601String(),
                ];
            }
        }

        if ($assetTransfer !== null) {
            $timeline[] = [
                'event'          => 'transfer_' . $assetTransfer->status,
                'reference'      => $assetTransfer->reference,
                'status'         => $assetTransfer->status,
                'failure_reason' => $assetTransfer->failure_reason,
                'at'             => $assetTransfer->completed_at?->toIso8601String()
                    ?? $assetTransfer->failed_at?->toIso8601String()
                    ?? $assetTransfer->initiated_at?->toIso8601String(),
            ];
        }

        if ($moneyRequest !== null) {
            $timeline[] = [
                'event'            => 'money_request_state',
                'money_request_id' => $moneyRequest->id,
                'status'           => $moneyRequest->status,
                'at'               => $moneyRequest->updated_at->toIso8601String(),
            ];
        }

        $warnings = [];
        if ($ledgerPosting !== null && count($projections) === 0) {
            $warnings[] = 'Ledger posting exists but no matching transaction_projections were found for this post-cutover movement.';
        } elseif ($assetTransfer !== null && count($projections) === 0) {
            $warnings[] = 'Transfer exists in asset_transfers but no matching transaction_projections were found.';
        } elseif ($assetTransfer !== null && count($projections) !== 2) {
            $warnings[] = 'Transfer projection count mismatch: expected 2 account-facing transaction_projections rows for an internal P2P transfer.';
        }

        if ($ledgerPosting === null && count($projections) > 0 && $this->isInspectableInternalMovement($transaction, $assetTransfer)) {
            $warnings[] = 'Transaction projections exist without a ledger posting. Treat this movement as legacy pre-cutover unless a posting backfill is explicitly documented.';
        }

        if ($relatedLedgerPostings !== []) {
            $warnings[] = 'Ledger posting has linked reversal or adjustment postings that must be reviewed together.';
        }

        if ($reconciliationExceptions !== []) {
            $warnings[] = 'Reconciliation exceptions reference this ledger posting chain.';
        }

        if (($minorFamilyContext['funding_attempt']['status'] ?? null) === MinorFamilyFundingAttempt::STATUS_SUCCESSFUL_UNCREDITED) {
            $warnings[] = 'Minor family funding attempt is successful at provider level but still uncredited. Wallet credit reconciliation is required.';
        }

        if (($minorFamilyContext['support_transfer']['status'] ?? null) === MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED) {
            $warnings[] = 'Minor family support transfer failed without a recorded wallet refund. Funds-at-risk reconciliation is required.';
        }

        return [
            'lookup' => array_filter([
                'trx'       => $trx,
                'reference' => $resolvedReference,
            ]),
            'authorized_transaction' => $transaction !== null ? [
                'trx'               => $transaction->trx,
                'remark'            => $transaction->remark,
                'status'            => $transaction->status,
                'verification_type' => $transaction->verification_type,
                'user_id'           => $transaction->user_id,
                'failure_reason'    => $transaction->failure_reason,
                'payload'           => $transaction->payload,
                'result'            => $transaction->result,
                'created_at'        => $transaction->created_at->toIso8601String(),
                'updated_at'        => $transaction->updated_at->toIso8601String(),
            ] : null,
            'ledger_posting' => $ledgerPosting !== null ? [
                'id'                         => $ledgerPosting->id,
                'authorized_transaction_trx' => $ledgerPosting->authorized_transaction_trx,
                'posting_type'               => $ledgerPosting->posting_type,
                'status'                     => $ledgerPosting->status,
                'asset_code'                 => $ledgerPosting->asset_code,
                'transfer_reference'         => $ledgerPosting->transfer_reference,
                'money_request_id'           => $ledgerPosting->money_request_id,
                'rule_version'               => $ledgerPosting->rule_version,
                'posted_at'                  => $ledgerPosting->posted_at?->toIso8601String(),
                'related_posting_id'         => $ledgerPosting->metadata['related_posting_id'] ?? null,
                'adjusted_by_posting_id'     => $ledgerPosting->metadata['adjusted_by_posting_id'] ?? null,
                'reversed_by_posting_id'     => $ledgerPosting->metadata['reversed_by_posting_id'] ?? null,
                'adjustment_reason'          => $ledgerPosting->metadata['adjustment_reason'] ?? null,
                'reversal_reason'            => $ledgerPosting->metadata['reversal_reason'] ?? null,
                'entries'                    => $ledgerPosting->entries
                    ->map(fn (LedgerEntry $entry): array => [
                        'id'            => $entry->id,
                        'account_uuid'  => $entry->account_uuid,
                        'asset_code'    => $entry->asset_code,
                        'signed_amount' => $entry->signed_amount,
                        'entry_type'    => $entry->entry_type,
                        'metadata'      => $entry->metadata,
                    ])
                    ->all(),
            ] : [],
            'related_ledger_postings' => $relatedLedgerPostings,
            'asset_transfer'          => $assetTransfer !== null ? [
                'reference'         => $assetTransfer->reference,
                'transfer_id'       => $assetTransfer->transfer_id,
                'status'            => $assetTransfer->status,
                'from_account_uuid' => $assetTransfer->from_account_uuid,
                'to_account_uuid'   => $assetTransfer->to_account_uuid,
                'from_asset_code'   => $assetTransfer->from_asset_code,
                'to_asset_code'     => $assetTransfer->to_asset_code,
                'from_amount'       => $assetTransfer->from_amount,
                'to_amount'         => $assetTransfer->to_amount,
                'failure_reason'    => $assetTransfer->failure_reason,
            ] : null,
            'projection_state'          => $projectionState,
            'reconciliation_exceptions' => $reconciliationExceptions,
            'transaction_projections'   => $projections,
            'minor_family_context'      => $minorFamilyContext,
            'money_request'             => $moneyRequest !== null ? [
                'id'                => $moneyRequest->id,
                'status'            => $moneyRequest->status,
                'requester_user_id' => $moneyRequest->requester_user_id,
                'recipient_user_id' => $moneyRequest->recipient_user_id,
                'amount'            => $moneyRequest->amount,
                'asset_code'        => $moneyRequest->asset_code,
                'trx'               => $moneyRequest->trx,
                'note'              => $moneyRequest->note,
            ] : null,
            'telemetry' => app(MaphaPayMoneyMovementTelemetry::class)->metricSnapshot(),
            'timeline'  => $timeline,
            'warnings'  => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProjectionState(
        ?AuthorizedTransaction $transaction,
        ?LedgerPosting $ledgerPosting,
        ?AssetTransfer $assetTransfer,
        int $projectionCount,
    ): array {
        $expectedCount = $this->isInspectableInternalMovement($transaction, $assetTransfer) ? 2 : null;

        if ($ledgerPosting !== null) {
            return [
                'status'             => $projectionCount === 0 ? 'lagging' : 'projected',
                'anchor'             => 'ledger_posting',
                'count'              => $projectionCount,
                'expected_count'     => $expectedCount,
                'ledger_posting_id'  => $ledgerPosting->id,
                'transfer_reference' => $ledgerPosting->transfer_reference,
            ];
        }

        if ($projectionCount > 0) {
            return [
                'status'             => 'legacy_projection_only',
                'anchor'             => 'projection_only',
                'count'              => $projectionCount,
                'expected_count'     => $expectedCount,
                'ledger_posting_id'  => null,
                'transfer_reference' => $assetTransfer?->reference,
            ];
        }

        return [
            'status'             => 'not_projected',
            'anchor'             => 'none',
            'count'              => $projectionCount,
            'expected_count'     => $expectedCount,
            'ledger_posting_id'  => null,
            'transfer_reference' => $assetTransfer?->reference,
        ];
    }

    private function isInspectableInternalMovement(?AuthorizedTransaction $transaction, ?AssetTransfer $assetTransfer): bool
    {
        if ($transaction !== null && in_array($transaction->remark, [
            AuthorizedTransaction::REMARK_SEND_MONEY,
            AuthorizedTransaction::REMARK_REQUEST_MONEY_RECEIVED,
        ], true)) {
            return true;
        }

        return $assetTransfer !== null
            && $assetTransfer->from_account_uuid !== null
            && $assetTransfer->to_account_uuid !== null;
    }

    private function resolveLedgerPosting(?AuthorizedTransaction $transaction, ?string $reference): ?LedgerPosting
    {
        $query = LedgerPosting::query()->with('entries');

        if ($transaction !== null) {
            return $query
                ->where('authorized_transaction_trx', $transaction->trx)
                ->first();
        }

        if ($reference === null || $reference === '') {
            return null;
        }

        return $query
            ->where('transfer_reference', $reference)
            ->first();
    }

    private function resolveAuthorizedTransaction(?string $trx, ?string $reference): ?AuthorizedTransaction
    {
        if ($trx !== null && $trx !== '') {
            return AuthorizedTransaction::query()->where('trx', $trx)->first();
        }

        if ($reference === null || $reference === '') {
            return null;
        }

        // @phpstan-ignore argument.type
        return AuthorizedTransaction::query()
            // @phpstan-ignore argument.type
            ->where('result->reference', $reference)
            // @phpstan-ignore argument.type
            ->orWhere('payload->reference', $reference)
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveRelatedLedgerPostings(?LedgerPosting $ledgerPosting): array
    {
        if ($ledgerPosting === null || $ledgerPosting->transfer_reference === null || $ledgerPosting->transfer_reference === '') {
            return [];
        }

        return array_values(LedgerPosting::query()
            ->where('transfer_reference', $ledgerPosting->transfer_reference)
            ->where('id', '!=', $ledgerPosting->id)
            ->orderBy('posted_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn (LedgerPosting $posting): array => [
                'id'                 => $posting->id,
                'posting_type'       => $posting->posting_type,
                'status'             => $posting->status,
                'transfer_reference' => $posting->transfer_reference,
                'related_posting_id' => $posting->metadata['related_posting_id'] ?? null,
                'adjustment_reason'  => $posting->metadata['adjustment_reason'] ?? null,
                'reversal_reason'    => $posting->metadata['reversal_reason'] ?? null,
                'posted_at'          => $posting->posted_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @param  list<string>  $postingChainIds
     * @return list<array<string, mixed>>
     */
    private function resolveReconciliationExceptions(array $postingChainIds): array
    {
        if ($postingChainIds === []) {
            return [];
        }

        /** @var Collection<int, array<string, mixed>> $reports */
        $reports = app(ReconciliationReportDataLoader::class)->load();

        return array_values($reports
            ->flatMap(function (array $report) use ($postingChainIds): array {
                $discrepancies = $report['discrepancies'] ?? [];
                if (! is_array($discrepancies)) {
                    return [];
                }

                $reportDate = is_string($report['date'] ?? null) ? $report['date'] : null;

                return collect($discrepancies)
                    ->filter(function (mixed $discrepancy) use ($postingChainIds): bool {
                        if (! is_array($discrepancy)) {
                            return false;
                        }

                        $reference = $discrepancy['ledger_posting_reference'] ?? null;

                        return is_string($reference) && in_array($reference, $postingChainIds, true);
                    })
                    ->map(function (array $discrepancy) use ($reportDate): array {
                        return array_merge($discrepancy, [
                            'report_date' => $reportDate,
                        ]);
                    })
                    ->values()
                    ->all();
            })
            ->values()
            ->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveMinorFamilyContext(?string $reference): ?array
    {
        if (! is_string($reference) || $reference === '') {
            return null;
        }

        /** @var MtnMomoTransaction|null $providerTransaction */
        $providerTransaction = MtnMomoTransaction::query()
            ->where('mtn_reference_id', $reference)
            ->orWhere('id', $reference)
            ->first();

        if ($providerTransaction === null) {
            return null;
        }

        $contextType = (string) ($providerTransaction->getAttribute('context_type') ?? '');
        $contextUuid = (string) ($providerTransaction->getAttribute('context_uuid') ?? '');

        /** @var MinorFamilyFundingAttempt|null $attempt */
        $attempt = null;
        /** @var MinorFamilySupportTransfer|null $transfer */
        $transfer = null;

        if ($contextType === MinorFamilyFundingAttempt::class) {
            $attempt = $contextUuid !== ''
                ? MinorFamilyFundingAttempt::query()->whereKey($contextUuid)->first()
                : null;
        } elseif ($contextType === MinorFamilySupportTransfer::class) {
            $transfer = $contextUuid !== ''
                ? MinorFamilySupportTransfer::query()->whereKey($contextUuid)->first()
                : null;
        }

        if ($attempt === null && $transfer === null) {
            $attempt = MinorFamilyFundingAttempt::query()
                ->where('mtn_momo_transaction_id', $providerTransaction->id)
                ->orWhere('provider_reference_id', $reference)
                ->first();

            if ($attempt !== null) {
                $contextType = MinorFamilyFundingAttempt::class;
                $contextUuid = $attempt->id;
            }
        }

        if ($attempt === null && $transfer === null) {
            $transfer = MinorFamilySupportTransfer::query()
                ->where('mtn_momo_transaction_id', $providerTransaction->id)
                ->orWhere('provider_reference_id', $reference)
                ->first();

            if ($transfer !== null) {
                $contextType = MinorFamilySupportTransfer::class;
                $contextUuid = $transfer->id;
            }
        }

        if ($attempt === null && $transfer === null) {
            return null;
        }

        /** @var MinorFamilyFundingLink|null $fundingLink */
        $fundingLink = $attempt !== null
            ? MinorFamilyFundingLink::query()->whereKey($attempt->funding_link_uuid)->first()
            : null;

        return [
            'context_type' => $contextType,
            'context_uuid' => $contextUuid,
            'provider_reference_id' => $providerTransaction->mtn_reference_id,
            'mtn_momo_transaction_id' => $providerTransaction->id,
            'funding_link' => $fundingLink !== null ? [
                'id' => $fundingLink->id,
                'status' => $fundingLink->status,
                'minor_account_uuid' => $fundingLink->minor_account_uuid,
                'amount_mode' => $fundingLink->amount_mode,
                'target_amount' => $fundingLink->target_amount,
                'collected_amount' => $fundingLink->collected_amount,
                'asset_code' => $fundingLink->asset_code,
            ] : null,
            'funding_attempt' => $attempt !== null ? [
                'id' => $attempt->id,
                'status' => $attempt->status,
                'minor_account_uuid' => $attempt->minor_account_uuid,
                'funding_link_uuid' => $attempt->funding_link_uuid,
                'provider_name' => $attempt->provider_name,
                'provider_reference_id' => $attempt->provider_reference_id,
                'amount' => $attempt->amount,
                'asset_code' => $attempt->asset_code,
                'wallet_credited_at' => $attempt->wallet_credited_at?->toIso8601String(),
                'failed_reason' => $attempt->failed_reason,
            ] : null,
            'support_transfer' => $transfer !== null ? [
                'id' => $transfer->id,
                'status' => $transfer->status,
                'minor_account_uuid' => $transfer->minor_account_uuid,
                'source_account_uuid' => $transfer->source_account_uuid,
                'provider_name' => $transfer->provider_name,
                'provider_reference_id' => $transfer->provider_reference_id,
                'amount' => $transfer->amount,
                'asset_code' => $transfer->asset_code,
                'wallet_refunded_at' => $transfer->wallet_refunded_at?->toIso8601String(),
                'failed_reason' => $transfer->failed_reason,
            ] : null,
        ];
    }
}
