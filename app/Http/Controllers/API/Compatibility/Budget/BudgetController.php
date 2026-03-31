<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Compatibility\Budget;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/budget
 *
 * Returns the budget overview for the authenticated user.
 */
class BudgetController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data'   => [
                'total_budget' => '0.00',
                'spent'        => '0.00',
                'remaining'    => '0.00',
                'categories'   => [],
            ],
        ]);
    }
}
