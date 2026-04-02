<?php

declare(strict_types=1);

namespace App\Domain\Asset\Projectors;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Account\Models\Transfer;
use App\Domain\Asset\Events\AssetTransferCompleted;
use App\Domain\Asset\Events\AssetTransferFailed;
use App\Domain\Asset\Events\AssetTransferInitiated;
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
            // Create transfer record
            Transfer::create(
                [
                    'uuid'              => (string) \Illuminate\Support\Str::uuid(),
                    'from_account_uuid' => $event->fromAccountUuid->toString(),
                    'to_account_uuid'   => $event->toAccountUuid->toString(),
                    'amount'            => $event->getFromAmount(),
                    'description'       => $event->description ?? "Asset transfer: {$event->fromAssetCode} to {$event->toAssetCode}",
                    'hash'              => $event->hash->getHash(),
                    'metadata'          => array_merge(
                        $event->metadata ?? [],
                        [
                            'from_asset_code' => $event->fromAssetCode,
                            'to_asset_code'   => $event->toAssetCode,
                            'from_amount'     => $event->getFromAmount(),
                            'to_amount'       => $event->getToAmount(),
                            'exchange_rate'   => $event->exchangeRate,
                            'is_cross_asset'  => $event->isCrossAssetTransfer(),
                            'status'          => 'initiated',
                        ]
                    ),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
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

            // Update transfer record status
            /** @var Transfer|null $transfer */
            $transfer = Transfer::where('hash', $event->hash->getHash())->first();
            if ($transfer) {
                $transfer->update(
                    [
                        'metadata' => array_merge(
                            $transfer->metadata ?? [],
                            [
                                'status'       => 'completed',
                                'completed_at' => now()->toISOString(),
                            ]
                        ),
                    ]
                );
            }

            Log::info(
                'Asset transfer completed successfully',
                [
                    'from_account'     => $event->fromAccountUuid->toString(),
                    'to_account'       => $event->toAccountUuid->toString(),
                    'from_asset'       => $event->fromAssetCode,
                    'to_asset'         => $event->toAssetCode,
                    'from_amount'      => $event->fromAmount->getAmount(),
                    'to_amount'        => $event->toAmount->getAmount(),
                    'from_new_balance' => $fromBalance->balance,
                    'to_new_balance'   => $toBalance->balance,
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
        /** @var \App\Domain\Payment\Models\Transfer|null $transfer */
        $transfer = null;
        try {
            // Update transfer record status
            /** @var Transfer|null $transfer */
            $transfer = Transfer::where('hash', $event->hash->getHash())->first();
            if ($transfer) {
                $transfer->update(
                    [
                        'metadata' => array_merge(
                            $transfer->metadata ?? [],
                            [
                                'status'         => 'failed',
                                'failure_reason' => $event->reason,
                                'failed_at'      => now()->toISOString(),
                            ]
                        ),
                    ]
                );
            }

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
}
