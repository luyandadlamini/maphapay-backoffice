<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Budget;

use App\Domain\Mobile\Models\UserBudget;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BudgetUpdateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
        ]);

        /** @var User $user */
        $user = $request->user();

        $budget = UserBudget::getCurrentBudget($user->uuid);

        if ($budget) {
            $budget->update(['monthly_budget' => $validated['amount']]);
        } else {
            $budget = UserBudget::create([
                'uuid'           => Str::uuid()->toString(),
                'user_uuid'      => $user->uuid,
                'monthly_budget' => $validated['amount'],
                'month'          => now()->month,
                'year'           => now()->year,
            ]);
        }

        return response()->json([
            'status'  => 'success',
            'message' => ['Monthly budget updated successfully'],
            'data'    => [
                'monthly_budget' => (float) $budget->monthly_budget,
            ],
        ]);
    }
}
