<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Budget;

use App\Domain\Mobile\Models\BudgetCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetCategoriesUpdateController extends Controller
{
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'icon' => 'nullable|string|max:50',
            'budget_amount' => 'sometimes|numeric|min:0',
            'sort_order' => 'sometimes|integer|min:0',
        ]);

        $user = $request->user();

        $category = BudgetCategory::where('id', $id)
            ->where('user_uuid', $user->uuid)
            ->first();

        if (! $category) {
            return response()->json([
                'status' => 'error',
                'message' => ['Category not found'],
            ], 404);
        }

        if ($category->is_system && isset($validated['name'])) {
            return response()->json([
                'status' => 'error',
                'message' => ['Cannot rename system category'],
            ], 400);
        }

        $category->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => ['Category updated successfully'],
            'data' => [
                'category' => [
                    'id' => (int) $category->id,
                    'slug' => $category->slug,
                    'name' => $category->name,
                    'icon' => $category->icon,
                    'budget_amount' => number_format((float) $category->budget_amount, 2, '.', ''),
                    'sort_order' => $category->sort_order,
                    'is_system' => $category->is_system,
                ],
            ],
        ]);
    }
}