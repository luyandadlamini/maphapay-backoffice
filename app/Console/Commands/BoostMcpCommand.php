<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BoostMcpCommand extends Command
{
    protected $signature = 'boost:mcp {handle=backoffice : MCP server handle to run}';

    protected $description = 'Start the MCP server via legacy boost:mcp alias';

    public function handle(): int
    {
        return $this->call('mcp:start', [
            'handle' => (string) $this->argument('handle'),
        ]);
    }
}
