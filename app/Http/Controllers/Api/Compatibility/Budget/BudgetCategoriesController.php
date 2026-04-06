<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Budget;

use App\Domain\Mobile\Models\BudgetCategory;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class BudgetCategoriesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        $categories = BudgetCategory::where('user_uuid', $user->uuid)
            ->orderBy('sort_order')
            ->get();

        $data = $categories->map(function (BudgetCategory $category): array {
            return [
                'id'            => (int) $category->id,
                'slug'          => $category->slug,
                'name'          => $category->name,
                'icon'          => $category->icon,
                'budget_amount' => number_format((float) $category->budget_amount, 2, '.', ''),
                'sort_order'    => $category->sort_order,
                'is_system'     => $category->is_system,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data'   => [
                'categories' => $data,
            ],
        ]);
    }
}
