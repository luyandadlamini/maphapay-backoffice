<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Pockets;

use App\Domain\Mobile\Models\Pocket;
use App\Domain\Mobile\Models\PocketSmartRule;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PocketsStoreController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'target_amount' => 'required|numeric|min:0',
            'target_date' => 'nullable|date_format:Y-m-d',
            'category' => 'nullable|string|in:' . implode(',', Pocket::CATEGORIES),
            'color' => 'nullable|string|max:20',
        ]);

        $user = $request->user();

        $pocket = Pocket::create([
            'uuid' => Str::uuid()->toString(),
            'user_uuid' => $user->uuid,
            'name' => $validated['name'],
            'target_amount' => $validated['target_amount'],
            'current_amount' => 0,
            'target_date' => $validated['target_date'] ?? null,
            'category' => $validated['category'] ?? 'general',
            'color' => $validated['color'] ?? '#4F8CFF',
            'is_completed' => false,
        ]);

        PocketSmartRule::create([
            'pocket_id' => $pocket->uuid,
            ...PocketSmartRule::defaults(),
        ]);

        $pocket->load('smartRule');

        return response()->json([
            'status' => 'success',
            'message' => ['Pocket created successfully'],
            'data' => [
                'pocket' => $this->formatPocket($pocket),
            ],
        ], 201);
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