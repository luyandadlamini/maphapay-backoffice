<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List recent transactions for an account UUID. Optionally filter by status (pending/completed/failed) or type (credit/debit/transfer). Returns formatted amounts with asset precision.')]
class TransactionInspectorTool extends Tool
{
    public function handle(Request $request): Response
    {
        $accountUuid = $request->get('account_uuid');

        if (! $accountUuid) {
            return Response::text((string) json_encode(['error' => 'account_uuid is required'], JSON_PRETTY_PRINT));
        }

        $limit = min((int) ($request->get('limit') ?? 20), 50);
        $status = $request->get('status');
        $type = $request->get('type');

        $where = ['tp.account_uuid = ?'];
        $params = [$accountUuid];

        if ($status) {
            $where[] = 'tp.status = ?';
            $params[] = $status;
        }

        if ($type) {
            $where[] = 'tp.type = ?';
            $params[] = $type;
        }

        $whereClause = implode(' AND ', $where);

        $transactions = DB::select(
            "SELECT
                tp.uuid,
                tp.type,
                tp.subtype,
                tp.status,
                tp.asset_code,
                tp.amount,
                a.precision,
                a.symbol,
                tp.description,
                tp.reference,
                tp.related_account_uuid,
                tp.parent_transaction_id,
                tp.transaction_group_uuid,
                tp.created_at
             FROM transaction_projections tp
             LEFT JOIN assets a ON a.code = tp.asset_code
             WHERE {$whereClause}
             ORDER BY tp.created_at DESC
             LIMIT ?",
            [...$params, $limit]
        );

        $formatted = array_map(function ($tx) {
            $divisor = 10 ** ($tx->precision ?? 2);
            $formatted = number_format($tx->amount / $divisor, $tx->precision ?? 2, '.', '');

            return [
                'uuid'            => $tx->uuid,
                'type'            => $tx->type,
                'subtype'         => $tx->subtype,
                'status'          => $tx->status,
                'asset'           => $tx->asset_code,
                'amount'          => "{$tx->symbol} {$formatted}",
                'description'     => $tx->description,
                'reference'       => $tx->reference,
                'related_account' => $tx->related_account_uuid,
                'parent_tx'       => $tx->parent_transaction_id,
                'group'           => $tx->transaction_group_uuid,
                'created_at'      => $tx->created_at,
            ];
        }, $transactions);

        $summary = DB::selectOne(
            "SELECT
                count(*) AS total,
                sum(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                sum(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) AS pending,
                sum(CASE WHEN status = 'failed'    THEN 1 ELSE 0 END) AS failed
             FROM transaction_projections
             WHERE account_uuid = ?",
            [$accountUuid]
        );

        return Response::text((string) json_encode([
            'account_uuid' => $accountUuid,
            'filters'      => array_filter(['status' => $status, 'type' => $type]),
            'summary'      => $summary,
            'transactions' => $formatted,
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_uuid' => $schema->string()
                ->description('The account UUID to inspect transactions for')
                ->required(),
            'status' => $schema->string()
                ->description('Filter by status: pending, completed, or failed'),
            'type' => $schema->string()
                ->description('Filter by type: credit, debit, or transfer'),
            'limit' => $schema->integer()
                ->description('Number of transactions to return (max 50, default 20)'),
        ];
    }
}
