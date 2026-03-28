<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\RequestMoney;

use App\Http\Controllers\Controller;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/request-money/reject/{id} — recipient declines a pending money request (Phase 5).
 */
class RequestMoneyRejectController extends Controller
{
    public function __invoke(Request $request, MoneyRequest $moneyRequest): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        if ($moneyRequest->status !== MoneyRequest::STATUS_PENDING) {
            return $this->errorResponse('This money request is not pending.', 422);
        }

        if ((int) $moneyRequest->recipient_user_id !== (int) $authUser->getAuthIdentifier()) {
            return $this->errorResponse('You are not the recipient of this money request.', 422);
        }

        $moneyRequest->update([
            'status' => MoneyRequest::STATUS_REJECTED,
        ]);

        return response()->json([
            'status' => 'success',
            'remark' => 'request_money_reject',
            'data'   => [],
        ]);
    }

    /**
     * @return array{status: string, remark: string, message: array<int, string>}
     */
    private function errorPayload(string $message): array
    {
        return [
            'status'  => 'error',
            'remark'  => 'request_money_reject',
            'message' => [$message],
        ];
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json($this->errorPayload($message), $status);
    }
}
