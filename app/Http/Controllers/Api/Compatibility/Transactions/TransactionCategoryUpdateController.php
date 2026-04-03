<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Transactions;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Account\Support\TransactionClassification;
use App\Domain\Mobile\Models\BudgetCategory;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class TransactionCategoryUpdateController extends Controller
{
    public function __invoke(Request $request, string $transactionUuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $allowedSlugs = BudgetCategory::DEFAULT_SLUGS;

        if (Schema::hasTable('budget_categories')) {
            $userCategorySlugs = BudgetCategory::query()
                ->where('user_uuid', $user->uuid)
                ->pluck('slug')
                ->filter()
                ->values()
                ->all();

            $allowedSlugs = array_values(array_unique([...$allowedSlugs, ...$userCategorySlugs]));
        }

        $validated = $request->validate([
            'category_slug' => ['required', 'string', Rule::in($allowedSlugs)],
        ]);

        $accountUuids = Account::query()
            ->where('user_uuid', $user->uuid)
            ->pluck('uuid');

        $transaction = TransactionProjection::query()
            ->where('uuid', $transactionUuid)
            ->whereIn('account_uuid', $accountUuids)
            ->where('status', 'completed')
            ->firstOrFail();

        $classification = TransactionClassification::forProjection($transaction);
        if (! $classification['editable_category']) {
            return response()->json([
                'status' => 'error',
                'message' => ['This transaction category cannot be changed.'],
            ], 422);
        }

        $transaction->forceFill([
            'user_category_slug' => $validated['category_slug'],
            'effective_category_slug' => $validated['category_slug'],
            'categorization_source' => 'user',
        ])->save();

        $transaction->refresh();
        $updated = TransactionClassification::forProjection($transaction);

        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => $transaction->uuid,
                'category_slug' => $updated['category_slug'],
                'category_label' => $updated['category_label'],
                'category_source' => $updated['category_source'],
                'editable_category' => $updated['editable_category'],
            ],
        ]);
    }
}
