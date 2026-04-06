<?php

declare(strict_types=1);

namespace App\Domain\Account\Workflows;

use App\Domain\Account\DataObjects\AccountUuid;
use Generator;
use Workflow\ActivityStub;
use Workflow\Workflow;

class AccountValidationWorkflow extends Workflow
{
    /**
     * Validate account for compliance/KYC requirements.
     */
    public function execute(
        AccountUuid $uuid,
        array $validationChecks,
        ?string $validatedBy = null
    ): Generator {
        return yield ActivityStub::make(
            AccountValidationActivity::class,
            $uuid,
            $validationChecks,
            $validatedBy
        );
    }
}
