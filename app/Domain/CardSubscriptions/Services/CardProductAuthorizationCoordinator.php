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
        $existing = $this->findReplay($user, $idempotencyKey);
        if ($existing instanceof AuthorizedTransaction) {
            return response()->json($this->replayEnvelope($existing));
        }

        $payload = array_merge(
            [
                'operation' => $operation,
                'user_id'   => (int) $user->id,
            ],
            $operationPayload,
        );

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
            ->where('payload->_idempotency_key', $idempotencyKey)
            ->latest('created_at')
            ->first();
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
