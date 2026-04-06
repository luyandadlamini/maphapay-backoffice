<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Budget;

use App\Models\User;
use App\Domain\Mobile\Models\UserBudget;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BudgetController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /** @var User $user */        $user = request()->user();

        $budget = UserBudget::getCurrentBudget($user->uuid);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'monthly_budget' => $budget ? (float) $budget->monthly_budget : null,
            ],
        ]);
    }
}
