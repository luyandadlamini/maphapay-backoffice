<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Services;

use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Asset\Models\AssetTransfer;
use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Models\MoneyRequest;

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

        $projections = $resolvedReference !== null
            ? TransactionProjection::query()
                ->where('reference', $resolvedReference)
                ->orderBy('created_at')
                ->get()
                ->map(fn (TransactionProjection $projection): array => [
                    'uuid' => $projection->uuid,
                    'account_uuid' => $projection->account_uuid,
                    'type' => $projection->type,
                    'subtype' => $projection->subtype,
                    'status' => $projection->status,
                    'amount' => $projection->formatted_amount,
                    'asset_code' => $projection->asset_code,
                    'description' => $projection->description,
                    'reference' => $projection->reference,
                    'created_at' => $projection->created_at?->toIso8601String(),
                ])
                ->all()
            : [];

        $timeline = [];
        if ($transaction !== null) {
            $timeline[] = [
                'event' => 'authorization_initiated',
                'status' => $transaction->status,
                'trx' => $transaction->trx,
                'at' => $transaction->created_at?->toIso8601String(),
            ];

            $policy = is_array($transaction->payload['_verification_policy'] ?? null)
                ? $transaction->payload['_verification_policy']
                : null;
            if ($policy !== null) {
                $timeline[] = [
                    'event' => 'challenge_decision',
                    'verification_policy' => $policy['verification_type'] ?? null,
                    'reason' => $policy['reason'] ?? null,
                    'risk_reason' => $policy['risk_reason'] ?? null,
                    'at' => $transaction->created_at?->toIso8601String(),
                ];
            }

            if ($transaction->status === AuthorizedTransaction::STATUS_COMPLETED) {
                $timeline[] = [
                    'event' => 'verification_succeeded',
                    'trx' => $transaction->trx,
                    'at' => $transaction->updated_at?->toIso8601String(),
                ];
            } elseif ($transaction->status === AuthorizedTransaction::STATUS_FAILED) {
                $timeline[] = [
                    'event' => 'verification_failed',
                    'trx' => $transaction->trx,
                    'failure_reason' => $transaction->failure_reason,
                    'verification_failures' => $transaction->verification_failures,
                    'at' => $transaction->updated_at?->toIso8601String(),
                ];
            } elseif ($transaction->status === AuthorizedTransaction::STATUS_EXPIRED) {
                $timeline[] = [
                    'event' => 'verification_expired',
                    'trx' => $transaction->trx,
                    'failure_reason' => $transaction->failure_reason,
                    'verification_failures' => $transaction->verification_failures,
                    'at' => $transaction->updated_at?->toIso8601String(),
                ];
            }
        }

        if ($assetTransfer !== null) {
            $timeline[] = [
                'event' => 'transfer_' . $assetTransfer->status,
                'reference' => $assetTransfer->reference,
                'status' => $assetTransfer->status,
                'failure_reason' => $assetTransfer->failure_reason,
                'at' => $assetTransfer->completed_at?->toIso8601String()
                    ?? $assetTransfer->failed_at?->toIso8601String()
                    ?? $assetTransfer->initiated_at?->toIso8601String(),
            ];
        }

        if ($moneyRequest !== null) {
            $timeline[] = [
                'event' => 'money_request_state',
                'money_request_id' => $moneyRequest->id,
                'status' => $moneyRequest->status,
                'at' => $moneyRequest->updated_at?->toIso8601String(),
            ];
        }

        $warnings = [];
        if ($assetTransfer !== null && count($projections) === 0) {
            $warnings[] = 'Transfer exists in asset_transfers but no matching transaction_projections were found.';
        } elseif ($assetTransfer !== null && count($projections) !== 2) {
            $warnings[] = 'Transfer projection count mismatch: expected 2 account-facing transaction_projections rows for an internal P2P transfer.';
        }

        return [
            'lookup' => array_filter([
                'trx' => $trx,
                'reference' => $resolvedReference,
            ]),
            'authorized_transaction' => $transaction !== null ? [
                'trx' => $transaction->trx,
                'remark' => $transaction->remark,
                'status' => $transaction->status,
                'verification_type' => $transaction->verification_type,
                'user_id' => $transaction->user_id,
                'failure_reason' => $transaction->failure_reason,
                'payload' => $transaction->payload,
                'result' => $transaction->result,
                'created_at' => $transaction->created_at?->toIso8601String(),
                'updated_at' => $transaction->updated_at?->toIso8601String(),
            ] : null,
            'asset_transfer' => $assetTransfer !== null ? [
                'reference' => $assetTransfer->reference,
                'transfer_id' => $assetTransfer->transfer_id,
                'status' => $assetTransfer->status,
                'from_account_uuid' => $assetTransfer->from_account_uuid,
                'to_account_uuid' => $assetTransfer->to_account_uuid,
                'from_asset_code' => $assetTransfer->from_asset_code,
                'to_asset_code' => $assetTransfer->to_asset_code,
                'from_amount' => $assetTransfer->from_amount,
                'to_amount' => $assetTransfer->to_amount,
                'failure_reason' => $assetTransfer->failure_reason,
            ] : null,
            'transaction_projections' => $projections,
            'money_request' => $moneyRequest !== null ? [
                'id' => $moneyRequest->id,
                'status' => $moneyRequest->status,
                'requester_user_id' => $moneyRequest->requester_user_id,
                'recipient_user_id' => $moneyRequest->recipient_user_id,
                'amount' => $moneyRequest->amount,
                'asset_code' => $moneyRequest->asset_code,
                'trx' => $moneyRequest->trx,
                'note' => $moneyRequest->note,
            ] : null,
            'telemetry' => app(MaphaPayMoneyMovementTelemetry::class)->metricSnapshot(),
            'timeline' => $timeline,
            'warnings' => $warnings,
        ];
    }

    private function resolveAuthorizedTransaction(?string $trx, ?string $reference): ?AuthorizedTransaction
    {
        if ($trx !== null && $trx !== '') {
            return AuthorizedTransaction::query()->where('trx', $trx)->first();
        }

        if ($reference === null || $reference === '') {
            return null;
        }

        return AuthorizedTransaction::query()
            ->where('result->reference', $reference)
            ->orWhere('payload->reference', $reference)
            ->first();
    }
}
