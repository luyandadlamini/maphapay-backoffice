<?php

declare(strict_types=1);

namespace App\Domain\Wallet\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Wallet\Contracts\WalletMovementRequest;
use App\Domain\Wallet\Contracts\WalletMovementResult;
use App\Domain\Wallet\Models\WalletProviderTransaction;
use App\Domain\Wallet\Providers\WalletProviderRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Provider-agnostic application service for outbound (disburse) wallet
 * movements. Debits the user's wallet *before* calling the adapter
 * (money leaves first; refund on failure callback handled by settler).
 * Idempotent on (provider_id, user_uuid, idempotency_key).
 */
final class WalletDisbursementService
{
    public function __construct(
        private readonly WalletProviderRegistry $registry,
        private readonly WalletOperationsService $walletOps,
    ) {
    }

    public function disburse(
        string $providerId,
        string $userUuid,
        string $providerAccountRef,
        string $linkToken,
        int $amountMinor,
        string $currency,
        string $idempotencyKey,
        string $callbackUrl,
        string $memo,
    ): WalletDisbursementResult {
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
        ): WalletDisbursementResult {
            $existing = WalletProviderTransaction::query()
                ->where('provider_id', $providerId)
                ->where('user_uuid', $userUuid)
                ->whereJsonContains('payload->idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return new WalletDisbursementResult(
                    transactionId: $existing->id,
                    providerRequestId: $existing->provider_request_id,
                    status: $existing->status,
                    failureReason: $this->payloadString($existing->payload, 'failure_reason'),
                    isReplay: true,
                );
            }

            $account = Account::query()
                ->where('user_uuid', $userUuid)
                ->orderBy('id')
                ->first();

            if ($account === null) {
                throw new RuntimeException('No account found for user.');
            }

            // Debit user wallet first; if adapter call later fails synchronously,
            // we leave the row in failed state and the operator can refund manually
            // (or extend MoneySettlerService to auto-refund failed disbursements).
            $this->walletOps->withdraw(
                $account->uuid,
                $currency,
                (string) $amountMinor,
                'wallet-disburse-debit:' . $providerId . ':' . $idempotencyKey,
                ['provider_id' => $providerId, 'idempotency_key' => $idempotencyKey],
            );

            $adapter = $this->registry->for($providerId);

            $movementResult = $adapter->disburse(new WalletMovementRequest(
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
                'type'                => WalletProviderTransaction::TYPE_DISBURSE,
                'status'              => $this->mapStatus($movementResult->status),
                'currency'            => $currency,
                'amount_minor'        => $amountMinor,
                'user_uuid'           => $userUuid,
                'payload'             => [
                    'idempotency_key'      => $idempotencyKey,
                    'provider_account_ref' => $providerAccountRef,
                    'memo'                 => $memo,
                    'failure_reason'       => $movementResult->failureReason,
                    'wallet_debited_at'    => now()->toIso8601String(),
                ],
                'settled_at' => $movementResult->status === WalletMovementResult::STATUS_FAILED ? now() : null,
            ]);

            return new WalletDisbursementResult(
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
