<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\GroupSavings;

use App\Domain\Account\Exceptions\NotEnoughFunds;
use App\Domain\GroupSavings\Services\GroupPocketTransferService;
use App\Http\Controllers\Controller;
use App\Models\GroupPocket;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class GroupPocketFundsController extends Controller
{
    public function __construct(
        private readonly GroupPocketTransferService $transferService,
    ) {
    }

    public function deposit(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $pocket = GroupPocket::findOrFail($id);

        $isMember = ThreadParticipant::query()
            ->where('thread_id', $pocket->thread_id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isMember) {
            return response()->json(['status' => 'error', 'message' => ['Not a member of this group']], 403);
        }

        if ($pocket->status === GroupPocket::STATUS_CLOSED) {
            return response()->json(['status' => 'error', 'message' => ['Pocket is closed']], 422);
        }

        try {
            $pocket = $this->transferService->deposit(
                user: $user,
                pocket: $pocket,
                amountMajor: (float) $validated['amount'],
            );
        } catch (NotEnoughFunds) {
            return response()->json(['status' => 'error', 'message' => ['Insufficient wallet balance']], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => [$e->getMessage()]], 422);
        }

        return response()->json([
            'status'  => 'success',
            'message' => ['Deposit successful'],
            'data'    => [
                'pocket' => [
                    'id'             => $pocket->id,
                    'current_amount' => number_format((float) $pocket->current_amount, 2, '.', ''),
                    'is_completed'   => $pocket->is_completed,
                    'status'         => $pocket->status,
                ],
            ],
        ]);
    }
}
