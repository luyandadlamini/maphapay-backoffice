<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Pockets;

use App\Domain\Mobile\Models\Pocket;
use App\Domain\Mobile\Services\PocketTransferService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PocketsWithdrawFundsController extends Controller
{
    public function __construct(
        private readonly PocketTransferService $pocketTransferService,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => ['Unauthenticated'],
            ], 401);
        }

        $pocket = Pocket::where('uuid', $id)
            ->where('user_uuid', $user->uuid)
            ->first();

        if (! $pocket) {
            return response()->json([
                'status' => 'error',
                'message' => ['Pocket not found'],
            ], 404);
        }

        $smartRule = $pocket->smartRule;
        if ($smartRule?->lock_pocket) {
            return response()->json([
                'status' => 'error',
                'message' => ['Pocket is locked. Unlock it first to withdraw funds.'],
            ], 400);
        }

        $pocketAmountStr = number_format((float) $pocket->current_amount, 2, '.', '');
        $withdrawAmountStr = number_format((float) $validated['amount'], 2, '.', '');
        if (bccomp($pocketAmountStr, $withdrawAmountStr, 2) < 0) {
            return response()->json([
                'status' => 'error',
                'message' => ['Insufficient funds in pocket'],
            ], 400);
        }

        try {
            $pocket = $this->pocketTransferService->transferFromPocket(
                user: $user,
                pocket: $pocket,
                amountMajor: (float) $validated['amount'],
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'status' => 'error',
                'message' => [$e->getMessage()],
            ], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => ['Funds withdrawn successfully'],
            'data' => [
                'pocket' => $this->formatPocket($pocket),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPocket(Pocket $pocket): array
    {
        $smartRule = $pocket->smartRule;

        return [
            'id' => $pocket->uuid,
            'user_id' => $pocket->user_uuid,
            'name' => $pocket->name,
            'target_amount' => number_format((float) $pocket->target_amount, 2, '.', ''),
            'current_amount' => number_format((float) $pocket->current_amount, 2, '.', ''),
            'target_date' => $pocket->target_date?->format('Y-m-d') ?? '2027-12-31',
            'category' => $pocket->category,
            'color' => $pocket->color,
            'is_completed' => $pocket->is_completed,
            'smart_rules' => $smartRule ? [
                'id' => (string) $smartRule->id,
                'pocket_id' => (string) $pocket->uuid,
                'round_up_change' => $smartRule->round_up_change,
                'auto_save_deposits' => $smartRule->auto_save_deposits,
                'auto_save_salary' => $smartRule->auto_save_salary,
                'lock_pocket' => $smartRule->lock_pocket,
            ] : [
                'round_up_change' => false,
                'auto_save_deposits' => false,
                'auto_save_salary' => false,
                'lock_pocket' => false,
            ],
        ];
    }
}