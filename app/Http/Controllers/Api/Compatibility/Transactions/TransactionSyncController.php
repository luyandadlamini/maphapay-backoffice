<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Transactions;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Account\Support\TransactionClassification;
use App\Domain\Account\Support\TransactionDisplay;
use App\Http\Controllers\Api\Compatibility\Concerns\ParsesChangedSince;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionSyncController extends Controller
{
    use ParsesChangedSince;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $changedSince = $this->parseChangedSince($request);

        $account = Account::where('user_uuid', $user->uuid)->first();

        if ($account === null) {
            return response()->json([
                'status'          => 'success',
                'remark'          => 'transactions_sync',
                'items'           => [],
                'deleted_ids'     => [],
                'next_sync_token' => $this->nextSyncToken([]),
            ]);
        }

        $query = TransactionProjection::query()
            ->where('account_uuid', $account->uuid)
            ->where('status', 'completed')
            ->orderBy('updated_at');

        if ($changedSince !== null) {
            $query->where('updated_at', '>', $changedSince);
        }

        $transactions = $query->get();

        return response()->json([
            'status'          => 'success',
            'remark'          => 'transactions_sync',
            'items'           => $transactions->map(fn (TransactionProjection $tx): array => $this->formatTransaction($tx))->values()->all(),
            'deleted_ids'     => [],
            'next_sync_token' => $this->nextSyncToken(
                TransactionProjection::query()
                    ->where('account_uuid', $account->uuid)
                    ->where('status', 'completed')
                    ->pluck('updated_at')
                    ->all(),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatTransaction(TransactionProjection $tx): array
    {
        $metadata = is_array($tx->metadata) ? $tx->metadata : [];
        $classification = TransactionClassification::forProjection($tx);
        $display = TransactionDisplay::buildForProjection(
            type: $tx->type,
            subtype: $tx->subtype,
            metadata: $metadata,
        );

        return [
            'id'                => $tx->uuid,
            'reference'         => $tx->reference,
            'description'       => $tx->description,
            'display'           => $display,
            'amount'            => $tx->formatted_amount,
            'type'              => $tx->type,
            'subtype'           => $tx->subtype,
            'asset_code'        => $tx->asset_code,
            'direction'         => $classification['direction'],
            'analytics_bucket'  => $classification['analytics_bucket'],
            'budget_eligible'   => $classification['budget_eligible'],
            'source_domain'     => $classification['source_domain'],
            'category_slug'     => $classification['category_slug'],
            'category_label'    => $classification['category_label'],
            'category_source'   => $classification['category_source'],
            'editable_category' => $classification['editable_category'],
            'created_at'        => $tx->created_at?->toIso8601String(),
            'updated_at'        => $tx->updated_at?->toIso8601String(),
        ];
    }
}
