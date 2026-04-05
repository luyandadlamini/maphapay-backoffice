<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Budget;

use App\Domain\Mobile\Models\BudgetCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BudgetCategoriesDeleteController extends Controller
{
    public function __invoke(string $id): JsonResponse
    {
        $user = request()->user();

        $category = BudgetCategory::where('id', $id)
            ->where('user_uuid', $user->uuid)
            ->first();

        if (! $category) {
            return response()->json([
                'status'  => 'error',
                'message' => ['Category not found'],
            ], 404);
        }

        if ($category->is_system) {
            return response()->json([
                'status'  => 'error',
                'message' => ['Cannot delete system category'],
            ], 400);
        }

        $category->delete();

        return response()->json([
            'status'  => 'success',
            'message' => ['Category deleted successfully'],
        ]);
    }
}
