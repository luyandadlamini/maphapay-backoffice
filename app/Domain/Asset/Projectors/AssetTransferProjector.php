<?php

declare(strict_types=1);

namespace App\Domain\Asset\Projectors;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Events\AssetTransferCompleted;
use App\Domain\Asset\Events\AssetTransferFailed;
use App\Domain\Asset\Events\AssetTransferInitiated;
use App\Domain\Asset\Models\AssetTransfer;
use Exception;
use Illuminate\Support\Facades\Log;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class AssetTransferProjector extends Projector
{
    /**
     * Handle asset transfer initiated event.
     */
    public function onAssetTransferInitiated(AssetTransferInitiated $event): void
    {
        try {
            // @phpstan-ignore argument.type
            $this->upsertTransfer(
                transferUuid: $event->aggregateRootUuid(),
                payload: [
                    'reference'         => $event->aggregateRootUuid(),
                    'hash'              => $event->hash->getHash(),
                    'from_account_uuid' => $event->fromAccountUuid->toString(),
                    'to_account_uuid'   => $event->toAccountUuid->toString(),
                    'from_asset_code'   => $event->fromAssetCode,
                    'to_asset_code'     => $event->toAssetCode,
                    'from_amount'       => $event->getFromAmount(),
                    'to_amount'         => $event->getToAmount(),
                    'exchange_rate'     => $event->exchangeRate,
                    'status'            => 'initiated',
                    'description'       => $event->description ?? "Asset transfer: {$event->fromAssetCode} to {$event->toAssetCode}",
                    'metadata'          => array_merge(
                        $event->metadata ?? [],
                        [
                            'from_asset_code' => $event->fromAssetCode,
                            'to_asset_code'   => $event->toAssetCode,
                            'from_amount'     => $event->getFromAmount(),
                            'to_amount'       => $event->getToAmount(),
                            'exchange_rate'   => $event->exchangeRate,
                            'is_cross_asset'  => $event->isCrossAssetTransfer(),
                        ],
                    ),
                    'initiated_at' => now(),
                ],
            );

            Log::info(
                'Asset transfer initiated',
                [
                    'from_account'  => $event->fromAccountUuid->toString(),
                    'to_account'    => $event->toAccountUuid->toString(),
                    'from_asset'    => $event->fromAssetCode,
                    'to_asset'      => $event->toAssetCode,
                    'from_amount'   => $event->getFromAmount(),
                    'to_amount'     => $event->getToAmount(),
                    'exchange_rate' => $event->exchangeRate,
                ]
            );
        } catch (Exception $e) {
            Log::error(
                'Error processing asset transfer initiation',
                [
                    'from_account' => $event->fromAccountUuid->toString(),
                    'to_account'   => $event->toAccountUuid->toString(),
                    'from_asset'   => $event->fromAssetCode,
                    'to_asset'     => $event->toAssetCode,
                    'error'        => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }

    /**
     * Handle asset transfer completed event.
     */
    public function onAssetTransferCompleted(AssetTransferCompleted $event): void
    {
        try {
            // Find accounts
            /** @var Account|null $fromAccount */
            $fromAccount = Account::where('uuid', $event->fromAccountUuid->toString())->first();
            /** @var Account|null $toAccount */
            $toAccount = Account::where('uuid', $event->toAccountUuid->toString())->first();

            if (! $fromAccount || ! $toAccount) {
                Log::error(
                    'Account not found for asset transfer completion',
                    [
                        'from_account' => $event->fromAccountUuid->toString(),
                        'to_account'   => $event->toAccountUuid->toString(),
                    ]
                );

                return;
            }

            // @phpstan-ignore argument.type
            $this->upsertTransfer(
                transferUuid: $event->aggregateRootUuid(),
                payload: [
                    'reference'         => $event->aggregateRootUuid(),
                    'transfer_id'       => $event->transferId ?? $event->aggregateRootUuid(),
                    'hash'              => $event->hash->getHash(),
                    'from_account_uuid' => $event->fromAccountUuid->toString(),
                    'to_account_uuid'   => $event->toAccountUuid->toString(),
                    'from_asset_code'   => $event->fromAssetCode,
                    'to_asset_code'     => $event->toAssetCode,
                    'from_amount'       => $event->fromAmount->getAmount(),
                    'to_amount'         => $event->toAmount->getAmount(),
                    'status'            => 'completed',
                    'description'       => $event->description,
                    'metadata'          => array_merge(
                        $event->metadata ?? [],
                        [
                            'transfer_id'    => $event->transferId,
                            'is_cross_asset' => $event->isCrossAssetTransfer(),
                        ],
                    ),
                    'completed_at' => now(),
                ],
            );

            Log::info(
                'Asset transfer completed successfully',
                [
                    'from_account' => $event->fromAccountUuid->toString(),
                    'to_account'   => $event->toAccountUuid->toString(),
                    'from_asset'   => $event->fromAssetCode,
                    'to_asset'     => $event->toAssetCode,
                    'from_amount'  => $event->fromAmount->getAmount(),
                    'to_amount'    => $event->toAmount->getAmount(),
                    'transfer_id'  => $event->transferId,
                ]
            );
        } catch (Exception $e) {
            Log::error(
                'Error processing asset transfer completion',
                [
                    'from_account' => $event->fromAccountUuid->toString(),
                    'to_account'   => $event->toAccountUuid->toString(),
                    'from_asset'   => $event->fromAssetCode,
                    'to_asset'     => $event->toAssetCode,
                    'error'        => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }

    /**
     * Handle asset transfer failed event.
     */
    public function onAssetTransferFailed(AssetTransferFailed $event): void
    {
        try {
            // @phpstan-ignore argument.type
            $this->upsertTransfer(
                transferUuid: $event->aggregateRootUuid(),
                payload: [
                    'reference'         => $event->aggregateRootUuid(),
                    'transfer_id'       => $event->transferId ?? $event->aggregateRootUuid(),
                    'hash'              => $event->hash->getHash(),
                    'from_account_uuid' => $event->fromAccountUuid->toString(),
                    'to_account_uuid'   => $event->toAccountUuid->toString(),
                    'from_asset_code'   => $event->fromAssetCode,
                    'to_asset_code'     => $event->toAssetCode,
                    'from_amount'       => $event->fromAmount->getAmount(),
                    'status'            => 'failed',
                    'failure_reason'    => $event->reason,
                    'metadata'          => array_merge(
                        $event->metadata ?? [],
                        [
                            'failure_reason' => $event->reason,
                        ],
                    ),
                    'failed_at' => now(),
                ],
            );

            Log::warning(
                'Asset transfer failed',
                [
                    'from_account' => $event->fromAccountUuid->toString(),
                    'to_account'   => $event->toAccountUuid->toString(),
                    'from_asset'   => $event->fromAssetCode,
                    'to_asset'     => $event->toAssetCode,
                    'reason'       => $event->reason,
                ]
            );
        } catch (Exception $e) {
            Log::error(
                'Error processing asset transfer failure',
                [
                    'from_account' => $event->fromAccountUuid->toString(),
                    'to_account'   => $event->toAccountUuid->toString(),
                    'reason'       => $event->reason,
                    'error'        => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertTransfer(string $transferUuid, array $payload): AssetTransfer
    {
        $existing = AssetTransfer::query()->where('uuid', $transferUuid)->first();
        $currentMetadata = is_array($existing?->metadata) ? $existing->metadata : [];
        $incomingMetadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        $payload['metadata'] = array_merge($currentMetadata, $incomingMetadata);
        $payload['reference'] ??= $transferUuid;
        $payload['transfer_id'] ??= ($existing !== null ? $existing->transfer_id : null) ?? $transferUuid;

        return AssetTransfer::query()->updateOrCreate(
            ['uuid' => $transferUuid],
            array_filter(
                $payload,
                static fn (mixed $value): bool => $value !== null,
            ),
        );
    }
}
