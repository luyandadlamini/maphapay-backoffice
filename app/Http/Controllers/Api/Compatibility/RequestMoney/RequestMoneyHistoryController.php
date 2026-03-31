<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\RequestMoney;

use App\Http\Controllers\Controller;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/request-money/history — money requests initiated by the authenticated user (Phase 5).
 */
class RequestMoneyHistoryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $authUser */
        $authUser = $request->user();

        $paginator = MoneyRequest::query()
            ->where('requester_user_id', $authUser->getAuthIdentifier())
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return response()->json([
            'status' => 'success',
            'remark' => 'request_money_history',
            'data'   => [
                'request_moneys' => [
                    'data' => array_map(
                        static fn (MoneyRequest $row): array => $row->toArray(),
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
