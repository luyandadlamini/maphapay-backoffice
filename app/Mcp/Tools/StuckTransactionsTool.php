<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Find pending transactions that have not completed within a given threshold. Useful for spotting stuck deposits, withdrawals, or transfers that may need manual intervention.')]
class StuckTransactionsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $minutesOld = max(1, (int) ($request->get('minutes_old') ?? 30));
        $limit = min((int) ($request->get('limit') ?? 25), 100);

        $stuck = DB::select(
            "SELECT
                tp.uuid,
                tp.account_uuid,
                tp.type,
                tp.subtype,
                tp.asset_code,
                tp.amount,
                a.precision,
                a.symbol,
                tp.description,
                tp.reference,
                tp.transaction_group_uuid,
                tp.created_at,
                TIMESTAMPDIFF(MINUTE, tp.created_at, NOW()) AS age_minutes
             FROM transaction_projections tp
             LEFT JOIN assets a ON a.code = tp.asset_code
             WHERE tp.status = 'pending'
               AND tp.created_at <= NOW() - INTERVAL ? MINUTE
             ORDER BY tp.created_at ASC
             LIMIT ?",
            [$minutesOld, $limit]
        );

        $grouped = [];

        foreach ($stuck as $tx) {
            $divisor = 10 ** ($tx->precision ?? 2);
            $formatted = number_format($tx->amount / $divisor, $tx->precision ?? 2, '.', '');

            $grouped[$tx->type][] = [
                'uuid'         => $tx->uuid,
                'account_uuid' => $tx->account_uuid,
                'subtype'      => $tx->subtype,
                'asset'        => $tx->asset_code,
                'amount'       => "{$tx->symbol} {$formatted}",
                'description'  => $tx->description,
                'reference'    => $tx->reference,
                'group'        => $tx->transaction_group_uuid,
                'created_at'   => $tx->created_at,
                'age_minutes'  => $tx->age_minutes,
            ];
        }

        return Response::text((string) json_encode([
            'threshold_minutes' => $minutesOld,
            'total_stuck'       => count($stuck),
            'by_type'           => $grouped,
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'minutes_old' => $schema->integer()
                ->description('Minimum age in minutes to consider a pending transaction stuck (default 30)'),
            'limit' => $schema->integer()
                ->description('Maximum number of stuck transactions to return (max 100, default 25)'),
        ];
    }
}
