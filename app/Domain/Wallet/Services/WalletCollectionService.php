<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Wallet\Contracts\WalletMovementRequest;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Providers\WalletProviderRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Provider-agnostic application service for inbound (collect) wallet
 * movements. Looks up the right adapter via WalletProviderRegistry,
 * persists state to wallet_provider_transactions, and is idempotent on
 * (provider_id, user_uuid, idempotency_key).
 *
 * Successful callbacks are processed by the corresponding ProviderSettler
 * which credits the user wallet — this service only initiates the
 * outbound call and records the pending row.
 */
final class WalletCollectionService
{
    public function __construct(
        private readonly WalletProviderRegistry $registry,
    ) {
    }

    public function collect(
        string $providerId,
        string $userUuid,
        string $providerAccountRef,
        string $linkToken,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        string $callbackUrl,
        string $memo,
    ): WalletCollectionResult {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero.');
        }
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key is required.');
        }

        return DB::transaction(function () use (
            $providerId,
            $userUuid,
            $providerAccountRef,
            $linkToken,
            $amountMinor,
            $currency,
            $idempotencyKey,
            $callbackUrl,
            $memo,
        ): WalletCollectionResult {
            // Idempotency: replay returns the existing row's result.
            $existing = WalletProviderTransaction::query()
                ->where('provider_id', $providerId)
                ->where('user_uuid', $userUuid)
                ->whereJsonContains('payload->idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return new WalletCollectionResult(
                    transactionId: $existing->id,
                    providerRequestId: $existing->provider_request_id,
                    status: $existing->status,
                    failureReason: $this->payloadString($existing->payload, 'failure_reason'),
                    isReplay: true,
                );
            }

            $adapter = $this->registry->for($providerId);

            $movementResult = $adapter->collect(new WalletMovementRequest(
                providerId: $providerId,
                providerAccountRef: $providerAccountRef,
                linkToken: $linkToken,
                amountMinor: $amountMinor,
                currency: $currency,
                idempotencyKey: $idempotencyKey,
                callbackUrl: $callbackUrl,
                memo: $memo,
            ));

            $row = WalletProviderTransaction::query()->create([
                'provider_id'         => $providerId,
                'provider_request_id' => $movementResult->providerRequestId,
                'type'                => WalletProviderTransaction::TYPE_COLLECT,
                'status'              => $this->mapStatus($movementResult->status),
                'currency'            => $currency,
                'amount_minor'        => $amountMinor,
                'user_uuid'           => $userUuid,
                'payload'             => [
                    'idempotency_key'      => $idempotencyKey,
                    'provider_account_ref' => $providerAccountRef,
                    'memo'                 => $memo,
                    'failure_reason'       => $movementResult->failureReason,
                ],
                'settled_at' => $movementResult->status === WalletMovementResult::STATUS_FAILED ? now() : null,
            ]);

            return new WalletCollectionResult(
                transactionId: $row->id,
                providerRequestId: $movementResult->providerRequestId,
                status: $row->status,
                failureReason: $movementResult->failureReason,
                isReplay: false,
            );
        });
    }

    private function mapStatus(string $movementStatus): string
    {
        return match ($movementStatus) {
            WalletMovementResult::STATUS_SUCCESSFUL => WalletProviderTransaction::STATUS_SUCCESSFUL,
            WalletMovementResult::STATUS_FAILED     => WalletProviderTransaction::STATUS_FAILED,
            default                                 => WalletProviderTransaction::STATUS_PENDING,
        };
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function payloadString(?array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
