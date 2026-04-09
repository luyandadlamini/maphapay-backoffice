<?php

declare(strict_types=1);

namespace App\Domain\Account\Support;

use App\Domain\Account\Models\TransactionProjection;
use Illuminate\Support\Str;

class InternalTransferProjectionWriter
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function create(
        string $fromAccountUuid,
        string $toAccountUuid,
        string $fromAssetCode,
        string $toAssetCode,
        int $fromAmount,
        int $toAmount,
        string $subtype,
        ?string $description,
        ?string $reference,
        array $metadata,
    ): void {
        TransactionProjection::create(
            $this->buildProjectionAttributes(
                accountUuid: $fromAccountUuid,
                type: 'transfer_out',
                subtype: $subtype,
                assetCode: $fromAssetCode,
                amount: $fromAmount,
                description: $description ?? 'Transfer to ' . substr($toAccountUuid, 0, 8),
                reference: $reference,
                metadata: array_merge($metadata, [
                    'to_account' => $toAccountUuid,
                ]),
            ),
        );

        TransactionProjection::create(
            $this->buildProjectionAttributes(
                accountUuid: $toAccountUuid,
                type: 'transfer_in',
                subtype: $subtype,
                assetCode: $toAssetCode,
                amount: $toAmount,
                description: $description ?? 'Transfer from ' . substr($fromAccountUuid, 0, 8),
                reference: $reference,
                metadata: array_merge($metadata, [
                    'from_account' => $fromAccountUuid,
                ]),
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
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
}
