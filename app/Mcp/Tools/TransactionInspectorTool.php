<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Monitoring\Services\MoneyMovementTransactionInspector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Inspect a money movement lifecycle by authorized transaction trx or transfer reference. Returns the joined authorized transaction, transfer audit row, account-history projections, money-request state, timeline, and telemetry snapshot.')]
class TransactionInspectorTool extends Tool
{
    public function __construct(
        private readonly MoneyMovementTransactionInspector $inspector,
    ) {}

    public function handle(Request $request): Response
    {
        $trx = $request->get('trx');
        $reference = $request->get('reference');

        if (! $trx && ! $reference) {
            return Response::text((string) json_encode(['error' => 'trx or reference is required'], JSON_PRETTY_PRINT));
        }

        return Response::text((string) json_encode(
            $this->inspector->inspect(
                is_string($trx) ? $trx : null,
                is_string($reference) ? $reference : null,
            ),
            JSON_PRETTY_PRINT,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'trx' => $schema->string()
                ->description('Authorized transaction trx identifier (for example TRX-ABC123)'),
            'reference' => $schema->string()
                ->description('Transfer reference / asset transfer UUID'),
        ];
    }
}
