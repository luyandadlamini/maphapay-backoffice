<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Budget;

use App\Models\User;
use App\Domain\Mobile\Models\BudgetCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BudgetCategoriesStoreController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'icon'          => 'nullable|string|max:50',
            'budget_amount' => 'nullable|numeric|min:0',
        ]);

        /** @var User $user */
        $user = $request->user();
        $slug = Str::slug($validated['name']);

        $existingCount = BudgetCategory::where('user_uuid', $user->uuid)->count();

        $category = BudgetCategory::create([
            'uuid'          => Str::uuid()->toString(),
            'user_uuid'     => $user->uuid,
            'name'          => $validated['name'],
            'slug'          => $slug,
            'icon'          => $validated['icon'] ?? null,
            'budget_amount' => $validated['budget_amount'] ?? 0,
            'sort_order'    => $existingCount + 1,
            'is_system'     => false,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => ['Category created successfully'],
            'data'    => [
                'category' => [
                    'id'            => (int) $category->id,
                    'slug'          => $category->slug,
                    'name'          => $category->name,
                    'icon'          => $category->icon,
                    'budget_amount' => number_format((float) $category->budget_amount, 2, '.', ''),
                    'sort_order'    => $category->sort_order,
                    'is_system'     => $category->is_system,
                ],
            ],
        ], 201);
    }
}
