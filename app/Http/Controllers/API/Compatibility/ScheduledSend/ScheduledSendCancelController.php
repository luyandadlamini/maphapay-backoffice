<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\ScheduledSend;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use App\Http\Controllers\Controller;
use App\Models\ScheduledSend;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/scheduled-send/cancel/{scheduledSend} — cancel a pending scheduled send (Phase 5).
 */
class ScheduledSendCancelController extends Controller
{
    public function __invoke(Request $request, ScheduledSend $scheduledSend): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        if ((int) $scheduledSend->sender_user_id !== (int) $authUser->getAuthIdentifier()) {
            return $this->errorResponse('You are not the sender of this scheduled send.', 422);
        }

        if ($scheduledSend->status !== ScheduledSend::STATUS_PENDING) {
            return $this->errorResponse('This scheduled send is not pending.', 422);
        }

        DB::transaction(function () use ($scheduledSend, $authUser): void {
            $scheduledSend->update(['status' => ScheduledSend::STATUS_CANCELLED]);

            // Also cancel the linked authorized_transaction so a stale OTP/PIN cannot
            // execute the wallet transfer after the user cancels (defense-in-depth;
            // ScheduledSendHandler also guards via a pre-transfer status check).
            if ($scheduledSend->trx) {
                AuthorizedTransaction::query()
                    ->where('trx', $scheduledSend->trx)
                    ->where('user_id', (int) $authUser->getAuthIdentifier())
                    ->where('status', AuthorizedTransaction::STATUS_PENDING)
                    ->update(['status' => AuthorizedTransaction::STATUS_CANCELLED]);
            }
        });

        return response()->json([
            'status' => 'success',
            'remark' => 'scheduled_send_cancel',
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
            'remark'  => 'scheduled_send_cancel',
            'message' => [$message],
        ];
    }

    private function errorResponse(string $message, int $status): JsonResponse
    {
        return response()->json($this->errorPayload($message), $status);
    }
}
