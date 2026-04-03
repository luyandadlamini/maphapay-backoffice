<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\ScheduledSend;

use App\Http\Controllers\Controller;
use App\Models\ScheduledSend;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/scheduled-send/index — paginated scheduled sends for the authenticated sender (Phase 5).
 */
class ScheduledSendIndexController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $paginator = ScheduledSend::query()
            ->where('sender_user_id', $authUser->getAuthIdentifier())
            ->orderByDesc('scheduled_for')
            ->paginate(15)
            ->withQueryString();

        return response()->json([
            'status' => 'success',
            'remark' => 'scheduled_send_history',
            'data'   => [
                'scheduled_sends' => [
                    'data' => array_map(
                        static fn (ScheduledSend $row): array => [
                            'id'                => $row->id,
                            'sender_user_id'    => $row->sender_user_id,
                            'recipient_user_id' => $row->recipient_user_id,
                            'amount'            => $row->amount,
                            'asset_code'        => $row->asset_code,
                            'note'              => $row->note,
                            'scheduled_for'     => $row->scheduled_for->toIso8601String(),
                            'status'            => $row->status,
                            'trx'               => $row->trx,
                            'created_at'        => $row->created_at->toIso8601String(),
                            'updated_at'        => $row->updated_at->toIso8601String(),
                        ],
                        $paginator->items(),
                    ),
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'total'        => $paginator->total(),
                ],
            ],
        ]);
    }
}
