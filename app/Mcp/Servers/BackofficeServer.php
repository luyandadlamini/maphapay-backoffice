<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AccountLookupTool;
use App\Mcp\Tools\ReversalAuditTool;
use App\Mcp\Tools\StuckTransactionsTool;
use App\Mcp\Tools\TransactionInspectorTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('MaphaPay Backoffice')]
#[Version('1.0.0')]
#[Instructions(
    'Admin diagnostic tools for the MaphaPay backoffice. ' .
    'Use account_lookup to find a user\'s account and balances by email. ' .
    'Use transaction_inspector to browse transactions for a specific account UUID. ' .
    'Use reversal_audit to inspect reversal history and link back to the original transactions. ' .
    'Use stuck_transactions to surface pending transactions that have not completed within a threshold.'
)]
class BackofficeServer extends Server
{
    protected array $tools = [
        AccountLookupTool::class,
        TransactionInspectorTool::class,
        ReversalAuditTool::class,
        StuckTransactionsTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
