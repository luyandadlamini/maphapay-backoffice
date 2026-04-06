<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Workflows\TransferWorkflow;
use Workflow\WorkflowStub;

class TransferService
{
    public function transfer(mixed $from, mixed $to, mixed $amount): void
    {
        $workflow = WorkflowStub::make(TransferWorkflow::class);
        $workflow->start(__account_uuid($from), __account_uuid($to), __money($amount));
    }
}
