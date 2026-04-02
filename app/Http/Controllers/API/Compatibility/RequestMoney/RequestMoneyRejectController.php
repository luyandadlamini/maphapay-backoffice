<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\RequestMoney;

use App\Domain\Monitoring\Services\MaphaPayMoneyMovementTelemetry;
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
    public function __construct(
        private readonly MaphaPayMoneyMovementTelemetry $telemetry,
    ) {}

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

        $fromStatus = $moneyRequest->status;
        $moneyRequest->update([
            'status' => MoneyRequest::STATUS_REJECTED,
        ]);
        $moneyRequest->refresh();

        $this->telemetry->logMoneyRequestTransition($moneyRequest, $fromStatus, MoneyRequest::STATUS_REJECTED, [
            'remark' => 'request_money_reject',
            'acted_by_user_id' => $authUser->getAuthIdentifier(),
        ]);

        return response()->json([
            'status' => 'success',
            'remark' => 'request_money_reject',
            'data' => [],
        ]);
    }

    /**
     * @return array{status: string, remark: string, message: array<int, string>}
     */
    private function errorPayload(string $message): array
    {
        return [
            'status' => 'error',
            'remark' => 'request_money_reject',
            'message' => [$message],
        ];
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json($this->errorPayload($message), $status);
    }
}
