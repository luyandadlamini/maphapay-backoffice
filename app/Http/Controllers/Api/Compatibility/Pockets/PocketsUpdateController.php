<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Pockets;

use App\Domain\Mobile\Models\Pocket;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PocketsUpdateController extends Controller
{
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'target_amount' => 'sometimes|numeric|min:0',
            'target_date'   => 'nullable|date_format:Y-m-d',
            'category'      => 'sometimes|string|in:' . implode(',', Pocket::CATEGORIES),
            'color'         => 'sometimes|string|max:20',
        ]);

        /** @var User $user */
        $user = $request->user();

        $pocket = Pocket::where('uuid', $id)
            ->where('user_uuid', $user->uuid)
            ->first();

        if (! $pocket) {
            return response()->json([
                'status'  => 'error',
                'message' => ['Pocket not found'],
            ], 404);
        }

        $pocket->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => ['Pocket updated successfully'],
            'data'    => [
                'pocket' => $this->formatPocket($pocket->fresh() ?? $pocket),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function formatPocket(Pocket $pocket): array
    {
        $smartRule = $pocket->smartRule;

        return [
            'id'             => $pocket->uuid,
            'user_id'        => $pocket->user_uuid,
            'name'           => $pocket->name,
            'target_amount'  => number_format((float) $pocket->target_amount, 2, '.', ''),
            'current_amount' => number_format((float) $pocket->current_amount, 2, '.', ''),
            'target_date'    => $pocket->target_date?->format('Y-m-d') ?? '2027-12-31',
            'category'       => $pocket->category,
            'color'          => $pocket->color,
            'is_completed'   => $pocket->is_completed,
            'smart_rules'    => $smartRule ? [
                'id'                 => (string) $smartRule->id,
                'pocket_id'          => (string) $pocket->uuid,
                'round_up_change'    => $smartRule->round_up_change,
                'auto_save_deposits' => $smartRule->auto_save_deposits,
                'auto_save_salary'   => $smartRule->auto_save_salary,
                'lock_pocket'        => $smartRule->lock_pocket,
            ] : [
                'round_up_change'    => false,
                'auto_save_deposits' => false,
                'auto_save_salary'   => false,
                'lock_pocket'        => false,
            ],
        ];
    }
}
