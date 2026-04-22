<?php

declare(strict_types=1);

namespace App\Domain\Custodian\Services;

final class ProviderWebhookNormalizer
{
    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     normalized_event_type: string,
     *     provider_reference: string|null,
     *     payload_hash: string,
     *     dedupe_key: string,
     *     finality_status: string,
     *     settlement_status: string,
     *     reconciliation_status: string,
     *     settlement_reference: string|null,
     *     reconciliation_reference: string|null,
     *     ledger_posting_reference: string|null
     * }
     */
    public function normalize(string $custodianName, array $payload, ?string $eventType, ?string $eventId): array
    {
        $normalizedEventType = $this->mapNormalizedEventType($custodianName, $eventType ?? 'unknown');
        $providerReference = $this->extractProviderReference($custodianName, $payload);
        $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'normalized_event_type' => $normalizedEventType,
            'provider_reference'    => $providerReference,
            'payload_hash'          => $payloadHash,
            'dedupe_key'            => $eventId !== null && $eventId !== ''
                ? sprintf('%s:event:%s', $custodianName, $eventId)
                : sprintf('%s:fallback:%s:%s:%s', $custodianName, $providerReference ?? 'unknown', $normalizedEventType, $payloadHash),
            'finality_status'          => $this->mapFinalityStatus($normalizedEventType),
            'settlement_status'        => $this->mapSettlementStatus($normalizedEventType),
            'reconciliation_status'    => 'pending',
            'settlement_reference'     => null,
            'reconciliation_reference' => null,
            'ledger_posting_reference' => null,
        ];
    }

    private function mapNormalizedEventType(string $custodianName, string $eventType): string
    {
        return match ([$custodianName, $eventType]) {
            ['paysera', 'payment.completed'],
            ['santander', 'transfer.completed'],
            ['mock', 'transaction.completed'] => 'payment_succeeded',
            ['paysera', 'payment.failed'],
            ['santander', 'transfer.rejected'],
            ['mock', 'transaction.failed'] => 'payment_failed',
            ['paysera', 'account.balance_changed'],
            ['mock', 'balance.updated'] => 'balance_changed',
            default => 'unknown',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractProviderReference(string $custodianName, array $payload): ?string
    {
        return match ($custodianName) {
            'paysera'   => $payload['payment_id'] ?? $payload['account_id'] ?? null,
            'santander' => $payload['transfer_id'] ?? $payload['account_number'] ?? null,
            'mock'      => $payload['transaction_id'] ?? $payload['account'] ?? null,
            default     => null,
        };
    }

    private function mapFinalityStatus(string $normalizedEventType): string
    {
        return match ($normalizedEventType) {
            'payment_succeeded' => 'succeeded',
            'payment_failed'    => 'failed',
            default             => 'pending',
        };
    }

    private function mapSettlementStatus(string $normalizedEventType): string
    {
        return match ($normalizedEventType) {
            'settlement_completed' => 'completed',
            'settlement_failed'    => 'failed',
            default                => 'pending',
        };
    }
}
