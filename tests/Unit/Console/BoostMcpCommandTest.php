<?php

declare(strict_types=1);

use App\Console\Commands\BoostMcpCommand;

describe('Boost MCP compatibility command', function () {
    it('uses the legacy boost:mcp signature', function () {
        $command = new BoostMcpCommand();

        expect($command->getName())->toBe('boost:mcp');
    });

    it('defaults the handle argument to backoffice', function () {
        $command = new BoostMcpCommand();

        expect($command->getDefinition()->getArgument('handle')->getDefault())
            ->toBe('backoffice');
    });
});
