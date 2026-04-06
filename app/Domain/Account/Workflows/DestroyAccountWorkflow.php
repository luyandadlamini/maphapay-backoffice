<?php

declare(strict_types=1);

namespace App\Domain\Account\Workflows;

use App\Domain\Account\DataObjects\AccountUuid;
use Generator;
use Workflow\ActivityStub;
use Workflow\Workflow;

class DestroyAccountWorkflow extends Workflow
{
    public function execute(AccountUuid $uuid): Generator
    {
        return yield ActivityStub::make(
            DestroyAccountActivity::class,
            $uuid
        );
    }
}
