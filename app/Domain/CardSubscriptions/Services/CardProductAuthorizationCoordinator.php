<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Services;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Domain\AuthorizedTransaction\Services\AuthorizedTransactionManager;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Starts PIN-gated {@see AuthorizedTransaction::REMARK_CARD_PRODUCT} flows for card monetisation HTTP endpoints.
 */
final class CardProductAuthorizationCoordinator
{
    public function __construct(
        private readonly AuthorizedTransactionManager $authorizedTransactionManager,
    ) {
    }

    /**
     * @param  array<string, mixed>  $operationPayload
     */
    public function begin(User $user, string $operation, array $operationPayload, string $idempotencyKey): JsonResponse
    {
        if ($idempotencyKey === '') {
            return response()->json([
                'status'  => 'error',
                'remark'  => 'idempotency_key_required',
                'message' => ['Idempotency-Key header is required for card product operations.'],
                'data'    => ['code' => 'IDEMPOTENCY_KEY_REQUIRED'],
            ], 400);
        }

        $payload = array_merge(
            [
                'operation' => $operation,
                'user_id'   => (int) $user->id,
            ],
            $operationPayload,
        );

        $existing = $this->findReplay($user, $idempotencyKey);
        if ($existing instanceof AuthorizedTransaction) {
            if (! $this->payloadMatches($existing, $payload)) {
                return response()->json([
                    'status'  => 'error',
                    'remark'  => 'idempotency_payload_mismatch',
                    'message' => ['The provided idempotency key has already been used with different request parameters.'],
                    'data'    => ['code' => 'IDEMPOTENCY_PAYLOAD_MISMATCH'],
                ], 409);
            }

            return response()->json($this->replayEnvelope($existing));
        }

        $txn = $this->authorizedTransactionManager->initiate(
            (int) $user->getAuthIdentifier(),
            AuthorizedTransaction::REMARK_CARD_PRODUCT,
            $payload,
            AuthorizedTransaction::VERIFICATION_PIN,
            $idempotencyKey,
        );

        return response()->json([
            'status' => 'success',
            'remark' => 'card_product',
            'data'   => [
                'next_step' => 'pin',
                'trx'       => $txn->trx,
            ],
        ]);
    }

    private function findReplay(User $user, string $idempotencyKey): ?AuthorizedTransaction
    {
        if ($idempotencyKey === '') {
            return null;
        }

        return AuthorizedTransaction::query()
            ->where('user_id', $user->id)
            ->where('remark', AuthorizedTransaction::REMARK_CARD_PRODUCT)
            // @phpstan-ignore-next-line Laravel supports JSON path where clauses.
            ->where('payload->_idempotency_key', $idempotencyKey)
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadMatches(AuthorizedTransaction $txn, array $payload): bool
    {
        $existingPayload = $txn->payload;
        if (is_string($existingPayload)) {
            $decoded = json_decode($existingPayload, true);
            $existingPayload = is_array($decoded) ? $decoded : [];
        }
        if (is_object($existingPayload)) {
            $existingPayload = (array) $existingPayload;
        }
        if (! is_array($existingPayload)) {
            return false;
        }

        unset($existingPayload['_idempotency_key']);

        return $this->canonicalPayloadHash($existingPayload) === $this->canonicalPayloadHash($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalPayloadHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($this->sortRecursive($payload), JSON_THROW_ON_ERROR));
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursive($item);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return array{status: string, remark: string, data: array<string, mixed>}
     */
    private function replayEnvelope(AuthorizedTransaction $txn): array
    {
        if ($txn->isCompleted() && is_array($txn->result)) {
            return [
                'status' => 'success',
                'remark' => 'card_product',
                'data'   => array_merge([
                    'next_step' => 'none',
                    'trx'       => $txn->trx,
                ], $txn->result),
            ];
        }

        return [
            'status' => 'success',
            'remark' => 'card_product',
            'data'   => [
                'next_step' => 'pin',
                'trx'       => $txn->trx,
            ],
        ];
    }
}
