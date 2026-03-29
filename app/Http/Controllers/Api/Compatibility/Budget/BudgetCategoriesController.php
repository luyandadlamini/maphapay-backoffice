<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Budget;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MaphaPay compatibility endpoint: budget categories for the authenticated user.
 *
 * Response envelope:
 * {
 *   status: 'success',
 *   data: {
 *     categories: [
 *       { id, slug, name, icon, budget_amount, sort_order, is_system }
 *     ]
 *   }
 * }
 */
class BudgetCategoriesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'categories' => [],
            ],
        ]);
    }
}
