<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Show recent transaction reversals. Optionally scope to an account UUID. Returns each reversal alongside its original transaction so you can verify the audit trail.')]
class ReversalAuditTool extends Tool
{
    public function handle(Request $request): Response
    {
        $accountUuid = $request->get('account_uuid');
        $limit = min((int) ($request->get('limit') ?? 15), 50);

        $where = ["tp.subtype = 'reversal' OR tp.parent_transaction_id IS NOT NULL"];
        $params = [];

        if ($accountUuid) {
            $where[] = 'tp.account_uuid = ?';
            $params[] = $accountUuid;
        }

        $whereClause = implode(' AND ', $where);

        $reversals = DB::select(
            "SELECT
                tp.uuid,
                tp.account_uuid,
                tp.type,
                tp.subtype,
                tp.status,
                tp.asset_code,
                tp.amount,
                a.precision,
                a.symbol,
                tp.description,
                tp.parent_transaction_id,
                tp.cancelled_at,
                tp.cancelled_by,
                tp.created_at
             FROM transaction_projections tp
             LEFT JOIN assets a ON a.code = tp.asset_code
             WHERE {$whereClause}
             ORDER BY tp.created_at DESC
             LIMIT ?",
            [...$params, $limit]
        );

        $result = [];

        foreach ($reversals as $reversal) {
            $divisor = 10 ** ($reversal->precision ?? 2);
            $formatted = number_format($reversal->amount / $divisor, $reversal->precision ?? 2, '.', '');

            $original = null;

            if ($reversal->parent_transaction_id) {
                $original = DB::selectOne(
                    'SELECT uuid, type, status, asset_code, amount, description, created_at
                     FROM transaction_projections
                     WHERE uuid = ?
                     LIMIT 1',
                    [$reversal->parent_transaction_id]
                );
            }

            $result[] = [
                'reversal' => [
                    'uuid'         => $reversal->uuid,
                    'account_uuid' => $reversal->account_uuid,
                    'type'         => $reversal->type,
                    'status'       => $reversal->status,
                    'amount'       => "{$reversal->symbol} {$formatted}",
                    'description'  => $reversal->description,
                    'cancelled_at' => $reversal->cancelled_at,
                    'cancelled_by' => $reversal->cancelled_by,
                    'created_at'   => $reversal->created_at,
                ],
                'original_transaction' => $original,
            ];
        }

        return Response::text((string) json_encode([
            'scope'     => $accountUuid ?? 'all accounts',
            'count'     => count($result),
            'reversals' => $result,
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_uuid' => $schema->string()
                ->description('Scope reversals to a specific account UUID (optional — omit for platform-wide view)'),
            'limit' => $schema->integer()
                ->description('Number of reversals to return (max 50, default 15)'),
        ];
    }
}
