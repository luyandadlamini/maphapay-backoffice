<?php

declare(strict_types=1);

namespace App\Domain\Custodian\Services;

use App\Domain\Custodian\Enums\ProviderFinalityStatus;
use App\Domain\Custodian\Enums\ProviderOperationType;
use App\Domain\Custodian\Enums\ProviderReconciliationStatus;
use App\Domain\Custodian\Enums\ProviderSettlementStatus;
use App\Domain\Custodian\Models\CustodianWebhook;
use App\Domain\Custodian\Models\ProviderOperation;

final class ProviderOperationService
{
    public function syncFromWebhook(CustodianWebhook $webhook): ProviderOperation
    {
        $operationType = $this->resolveOperationType($webhook);
        $providerReference = $webhook->provider_reference;
        $internalReference = $webhook->transaction_id ?? $webhook->custodian_account_id;
        $operationKey = $this->buildOperationKey($webhook, $operationType, $providerReference);

        /** @var ProviderOperation $operation */
        $operation = ProviderOperation::query()->firstOrNew([
            'operation_key' => $operationKey,
        ]);

        $operation->provider_family = 'custodian';
        $operation->provider_name = $webhook->custodian_name;
        $operation->operation_type = $operationType;
        $operation->normalized_event_type = $webhook->normalized_event_type;
        $operation->provider_reference = $providerReference ?? $operation->provider_reference;
        $operation->internal_reference = $internalReference ?? $operation->internal_reference;
        $operation->finality_status = ProviderFinalityStatus::from($webhook->finality_status);
        $operation->settlement_status = ProviderSettlementStatus::from($webhook->settlement_status);
        $operation->reconciliation_status = ProviderReconciliationStatus::from($webhook->reconciliation_status);
        $operation->settlement_reference = $webhook->settlement_reference ?? $operation->settlement_reference;
        $operation->reconciliation_reference = $webhook->reconciliation_reference ?? $operation->reconciliation_reference;
        $operation->ledger_posting_reference = $webhook->ledger_posting_reference ?? $operation->ledger_posting_reference;
        /** @var int<0, max> $latestWebhookId */
        $latestWebhookId = $webhook->id;
        $operation->latest_webhook_id = $latestWebhookId;
        $operation->metadata = array_filter([
            'event_type'     => $webhook->event_type,
            'event_id'       => $webhook->event_id,
            'webhook_status' => $webhook->status,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        $operation->save();

        if ($webhook->provider_operation_id !== $operation->getKey()) {
            $webhook->forceFill([
                'provider_operation_id' => $operation->getKey(),
            ])->save();
        }

        return $operation;
    }

    private function resolveOperationType(CustodianWebhook $webhook): ProviderOperationType
    {
        return match ($webhook->normalized_event_type ?? $webhook->event_type) {
            'payment_succeeded', 'payment_failed' => ProviderOperationType::TRANSFER,
            'balance_changed' => ProviderOperationType::BALANCE_SYNC,
            default           => str_contains($webhook->event_type, 'status')
                ? ProviderOperationType::ACCOUNT_STATUS
                : ProviderOperationType::UNKNOWN,
        };
    }

    private function buildOperationKey(
        CustodianWebhook $webhook,
        ProviderOperationType $operationType,
        ?string $providerReference,
    ): string {
        return sprintf(
            'custodian:%s:%s:%s',
            $webhook->custodian_name,
            $operationType->value,
            $providerReference ?? 'webhook:' . $webhook->uuid
        );
    }
}
