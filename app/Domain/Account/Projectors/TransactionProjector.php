<?php

declare(strict_types=1);

namespace App\Domain\Account\Projectors;

use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Account\Support\TransactionClassification;
use App\Domain\Account\Support\TransactionDisplay;
use App\Domain\Asset\Events\AssetTransactionCreated;
use App\Domain\Asset\Events\AssetTransferCompleted;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class TransactionProjector extends Projector
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function buildProjectionAttributes(
        string $accountUuid,
        string $type,
        ?string $subtype,
        string $assetCode,
        int $amount,
        ?string $description,
        ?string $reference,
        array $metadata,
    ): array {
        $display = TransactionDisplay::buildForProjection(
            type: $type,
            subtype: $subtype,
            metadata: $metadata,
        );
        $classification = TransactionClassification::defaults(
            type: $type,
            subtype: $subtype,
            metadata: $metadata,
        );

        if ($display !== null) {
            $metadata['display'] = $display;
        }

        return [
            'uuid'         => (string) Str::uuid(),
            'account_uuid' => $accountUuid,
            'type'         => $type,
            'subtype'      => $subtype,
            'asset_code'   => $assetCode,
            'amount'       => $amount,
            'description'  => $description,
            'reference'    => $reference,
            'hash'         => hash('sha512', implode('|', [
                $accountUuid,
                $type,
                (string) $subtype,
                $assetCode,
                (string) $amount,
                (string) $reference,
                (string) ($metadata['event_uuid'] ?? Str::uuid()->toString()),
            ])),
            'status'                  => 'completed',
            'metadata'                => $metadata,
            'analytics_bucket'        => $classification['analytics_bucket'],
            'budget_eligible'         => $classification['budget_eligible'],
            'source_domain'           => $classification['source_domain'],
            'system_category_slug'    => $classification['system_category_slug'],
            'effective_category_slug' => $classification['system_category_slug'],
            'categorization_source'   => 'system',
        ];
    }

    private function subtypeForAssetTransactionCreated(AssetTransactionCreated $event): ?string
    {
        $source = $event->metadata['source'] ?? null;
        $direction = $event->metadata['direction'] ?? null;

        if ($source === 'pocket_transfer') {
            return match ($direction) {
                'to_pocket'   => 'pocket_deposit',
                'from_pocket' => 'pocket_withdrawal',
                default       => 'pocket_transfer',
            };
        }

        return $event->metadata['subtype'] ?? $event->metadata['operation_type'] ?? null;
    }

    private function subtypeForAssetTransferCompleted(AssetTransferCompleted $event): string
    {
        return (string) ($event->metadata['operation_type'] ?? $event->metadata['subtype'] ?? 'send_money');
    }

    /**
     * Handle asset transaction created event.
     */
    public function onAssetTransactionCreated(AssetTransactionCreated $event): void
    {
        try {
            $metadata = array_merge(
                $event->metadata ?? [],
                [
                    'event_type' => 'AssetTransactionCreated',
                    'event_uuid' => $event->aggregateRootUuid(),
                ],
            );

            TransactionProjection::create(
                $this->buildProjectionAttributes(
                    accountUuid: (string) $event->accountUuid,
                    type: $event->isCredit() ? 'deposit' : 'withdrawal',
                    subtype: $this->subtypeForAssetTransactionCreated($event),
                    assetCode: $event->assetCode,
                    amount: $event->getAmount(),
                    description: $event->description ?? ($event->isCredit() ? 'Deposit' : 'Withdrawal'),
                    reference: $event->transactionId ?? null,
                    metadata: $metadata,
                )
            );

            Log::info(
                'Transaction projection created for AssetTransactionCreated',
                [
                    'account_uuid' => (string) $event->accountUuid,
                    'asset_code'   => $event->assetCode,
                    'amount'       => $event->getAmount(),
                    'event_uuid'   => $event->aggregateRootUuid(),
                    'connection'   => TransactionProjection::query()->getModel()->getConnectionName(),
                ]
            );
        } catch (Exception $e) {
            Log::error(
                'Error creating transaction projection',
                [
                    'event'      => 'AssetTransactionCreated',
                    'error'      => $e->getMessage(),
                    'event_uuid' => $event->aggregateRootUuid(),
                    'connection' => TransactionProjection::query()->getModel()->getConnectionName(),
                ]
            );
        }
    }

    /**
     * Handle asset transfer completed event.
     */
    public function onAssetTransferCompleted(AssetTransferCompleted $event): void
    {
        try {
            $subtype = $this->subtypeForAssetTransferCompleted($event);
            $baseMetadata = array_merge(
                $event->metadata ?? [],
                [
                    'event_type' => 'AssetTransferCompleted',
                    'event_uuid' => $event->aggregateRootUuid(),
                ],
            );

            // Create debit transaction for sender
            TransactionProjection::create(
                $this->buildProjectionAttributes(
                    accountUuid: (string) $event->fromAccountUuid,
                    type: 'transfer_out',
                    subtype: $subtype,
                    assetCode: $event->fromAssetCode,
                    amount: $event->fromAmount->getAmount(),
                    description: $event->description ?? 'Transfer to ' . substr((string) $event->toAccountUuid, 0, 8),
                    reference: $event->transferId ?? null,
                    metadata: array_merge($baseMetadata, [
                        'to_account' => (string) $event->toAccountUuid,
                    ]),
                )
            );

            // Create credit transaction for receiver
            TransactionProjection::create(
                $this->buildProjectionAttributes(
                    accountUuid: (string) $event->toAccountUuid,
                    type: 'transfer_in',
                    subtype: $subtype,
                    assetCode: $event->toAssetCode,
                    amount: $event->toAmount->getAmount(),
                    description: $event->description ?? 'Transfer from ' . substr((string) $event->fromAccountUuid, 0, 8),
                    reference: $event->transferId ?? null,
                    metadata: array_merge($baseMetadata, [
                        'from_account' => (string) $event->fromAccountUuid,
                    ]),
                )
            );

            Log::info(
                'Transaction projections created for AssetTransferCompleted',
                [
                    'from_account' => (string) $event->fromAccountUuid,
                    'to_account'   => (string) $event->toAccountUuid,
                    'asset_code'   => $event->fromAssetCode,
                    'amount'       => $event->fromAmount->getAmount(),
                    'event_uuid'   => $event->aggregateRootUuid(),
                    'connection'   => TransactionProjection::query()->getModel()->getConnectionName(),
                ]
            );
        } catch (Exception $e) {
            Log::error(
                'Error creating transaction projections for transfer',
                [
                    'event'              => 'AssetTransferCompleted',
                    'error'              => $e->getMessage(),
                    'event_uuid'         => $event->aggregateRootUuid(),
                    'transfer_reference' => $event->transferId ?? null,
                    'from_account_uuid'  => (string) $event->fromAccountUuid,
                    'to_account_uuid'    => (string) $event->toAccountUuid,
                    'connection'         => TransactionProjection::query()->getModel()->getConnectionName(),
                ]
            );
        }
    }
}
