<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Pockets;

use App\Domain\Mobile\Models\Pocket;
use App\Domain\Mobile\Models\PocketSmartRule;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PocketsUpdateRulesController extends Controller
{
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'round_up_change' => 'nullable|boolean',
            'auto_save_deposits' => 'nullable|boolean',
            'auto_save_salary' => 'nullable|boolean',
            'lock_pocket' => 'nullable|boolean',
        ]);

        $user = $request->user();

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

        if (! $smartRule) {
            $smartRule = PocketSmartRule::create([
                'pocket_id' => $pocket->uuid,
                ...PocketSmartRule::defaults(),
            ]);
        }

        $smartRule->update(array_filter([
            'round_up_change' => $validated['round_up_change'] ?? null,
            'auto_save_deposits' => $validated['auto_save_deposits'] ?? null,
            'auto_save_salary' => $validated['auto_save_salary'] ?? null,
            'lock_pocket' => $validated['lock_pocket'] ?? null,
        ], fn ($value) => $value !== null));

        return response()->json([
            'status' => 'success',
            'message' => ['Smart rules updated successfully'],
            'data' => [
                'pocket' => $this->formatPocket($pocket->fresh()),
            ],
        ]);
    }

    private function formatPocket(Pocket $pocket): array
    {
        $smartRule = $pocket->smartRule;

        return [
            'id' => (string) $pocket->id,
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