<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Services;

use App\Domain\Account\DataObjects\Money;
use App\Domain\Asset\Aggregates\AssetTransferAggregate;
use App\Domain\Asset\Models\Asset;
use App\Domain\Shared\Money\MoneyConverter;
use RuntimeException;

/**
 * Canonical internal P2P transfer executor for compat money movement.
 *
 * Send-money and request-money acceptance both use this service so funds move
 * through the same aggregate-backed execution path and produce the same event trail.
 */
class InternalP2pTransferService
{
    /**
     * @return array{amount: string, asset_code: string, reference: string}
     */
    public function execute(
        string $fromAccountUuid,
        string $toAccountUuid,
        string $amount,
        string $assetCode,
        string $reference,
    ): array {
        $asset = Asset::query()->where('code', $assetCode)->firstOrFail();
        $amountMinor = MoneyConverter::forAsset($amount, $asset);

        try {
            $aggregate = AssetTransferAggregate::retrieve($reference);
            $money = new Money((int) $amountMinor);

            if ($aggregate->getStatus() === null) {
                $aggregate->initiate(
                    fromAccountUuid: __account_uuid($fromAccountUuid),
                    toAccountUuid: __account_uuid($toAccountUuid),
                    fromAssetCode: $assetCode,
                    toAssetCode: $assetCode,
                    fromAmount: $money,
                    toAmount: $money,
                    description: "Transfer: {$reference}",
                );
            }

            $aggregate
                ->complete($reference)
                ->persist();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                sprintf('Failed to persist internal P2P transfer [%s]: %s', $reference, $e->getMessage()),
                previous: $e,
            );
        }

        return [
            'amount'     => MoneyConverter::toMajorUnitString($amountMinor, $asset->precision),
            'asset_code' => $assetCode,
            'reference'  => $reference,
        ];
    }
}

