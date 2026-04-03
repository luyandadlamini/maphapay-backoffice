<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Transactions;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Account\Support\TransactionClassification;
use App\Domain\Account\Support\TransactionDisplay;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MaphaPay compatibility endpoint: paginated transaction history for the authenticated user.
 *
 * Response envelope: { status, remark, data: { transactions: {paginated}, subtypes: [...] } }
 *
 * Each row exposes TransactionProjection's canonical field names so the mobile client
 * reads the domain model directly rather than legacy alias fields:
 *
 *   id          — uuid
 *   reference   — transaction reference (e.g. "REF-ABC123")
 *   description — human-readable description
 *   amount      — major-unit string (e.g. "10.50") via formatted_amount accessor
 *   type        — domain type: "deposit" | "withdrawal" | "transfer_in" | "transfer_out"
 *   subtype     — domain subtype: "send_money" | "request_money" | etc.
 *   asset_code  — "SZL"
 *   created_at  — ISO 8601 timestamp
 *
 * Optional query filters:
 *   - type    : matches the `type` column exactly, or the UI aliases
 *               "income" => ["deposit", "transfer_in"]
 *               "expense" => ["withdrawal", "transfer_out"]
 *               "transfer" => ["transfer_in", "transfer_out"]
 *   - subtype : matches the `subtype` column (replaces legacy "remark" filter)
 *   - search  : substring match on description or reference
 *   - page    : page number (default 1), 15 per page
 */
class TransactionHistoryController extends Controller
{
    private const PER_PAGE = 15;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $account = Account::where('user_uuid', $user->uuid)->first();

        if ($account === null) {
            return response()->json([
                'status' => 'success',
                'remark' => 'transactions',
                'data'   => [
                    'transactions' => [
                        'data'          => [],
                        'current_page'  => 1,
                        'last_page'     => 1,
                        'next_page_url' => null,
                        'total'         => 0,
                    ],
                    'subtypes' => [],
                ],
            ]);
        }

        $query = TransactionProjection::where('account_uuid', $account->uuid)
            ->where('status', 'completed')
            ->orderByDesc('created_at');

        if ($request->filled('type')) {
            $typeFilter = $request->string('type')->toString();

            if ($typeFilter === 'income') {
                $query->whereIn('type', ['deposit', 'transfer_in']);
            } elseif ($typeFilter === 'expense') {
                $query->whereIn('type', ['withdrawal', 'transfer_out']);
            } elseif ($typeFilter === 'transfer') {
                $query->whereIn('type', ['transfer_in', 'transfer_out']);
            } else {
                $query->where('type', $typeFilter);
            }
        }

        if ($request->filled('subtype')) {
            $query->where('subtype', $request->string('subtype')->toString());
        }

        if ($request->filled('search')) {
            $term = $request->string('search')->toString();
            $query->where(function ($q) use ($term): void {
                $q->where('description', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', "%{$term}%")
                    ->orWhere('uuid', 'like', "%{$term}%")
                    ->orWhere('metadata->display->title', 'like', "%{$term}%")
                    ->orWhere('metadata->display->counterparty_name', 'like', "%{$term}%")
                    ->orWhere('metadata->display->note_preview', 'like', "%{$term}%")
                    ->orWhere('metadata->p2p_display->sender_label', 'like', "%{$term}%")
                    ->orWhere('metadata->p2p_display->recipient_label', 'like', "%{$term}%")
                    ->orWhere('metadata->note', 'like', "%{$term}%");
            });
        }

        $paginator = $query->paginate(self::PER_PAGE);

        $rows = collect($paginator->items())->map(function (TransactionProjection $tx): array {
            $metadata = is_array($tx->metadata) ? $tx->metadata : [];
            $classification = TransactionClassification::forProjection($tx);
            $display = TransactionDisplay::buildForProjection(
                type: $tx->type,
                subtype: $tx->subtype,
                metadata: $metadata,
            );

            return [
                'id'               => $tx->uuid,
                'reference'        => $tx->reference,
                'description'      => $tx->description,
                'display'          => $display,
                'amount'           => $tx->formatted_amount,
                'type'             => $tx->type,
                'subtype'          => $tx->subtype,
                'asset_code'       => $tx->asset_code,
                'direction'        => $classification['direction'],
                'analytics_bucket' => $classification['analytics_bucket'],
                'budget_eligible'  => $classification['budget_eligible'],
                'source_domain'    => $classification['source_domain'],
                'category_slug'    => $classification['category_slug'],
                'category_label'   => $classification['category_label'],
                'category_source'  => $classification['category_source'],
                'editable_category' => $classification['editable_category'],
                'created_at'       => $tx->created_at?->toIso8601String(),
            ];
        })->values()->all();

        $subtypes = TransactionProjection::where('account_uuid', $account->uuid)
            ->where('status', 'completed')
            ->whereNotNull('subtype')
            ->distinct()
            ->pluck('subtype')
            ->filter()
            ->values()
            ->all();

        return response()->json([
            'status' => 'success',
            'remark' => 'transactions',
            'data'   => [
                'transactions' => [
                    'data'          => $rows,
                    'current_page'  => $paginator->currentPage(),
                    'last_page'     => $paginator->lastPage(),
                    'next_page_url' => $paginator->nextPageUrl(),
                    'total'         => $paginator->total(),
                ],
                'subtypes' => $subtypes,
            ],
        ]);
    }
}
