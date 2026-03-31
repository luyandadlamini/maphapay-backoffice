<?php

namespace App\Domain\Account\Services;

use App\Domain\Account\Aggregates\LedgerAggregate;
use App\Domain\Account\Aggregates\TransactionAggregate;
use App\Domain\Account\Aggregates\TransferAggregate;
use App\Domain\Account\DataObjects\Account;
use App\Domain\Account\Workflows\CreateAccountWorkflow;
use App\Domain\Account\Workflows\DepositAccountWorkflow;
use App\Domain\Account\Workflows\DestroyAccountWorkflow;
use App\Domain\Account\Workflows\WithdrawAccountWorkflow;
use Workflow\WorkflowStub;

class AccountService
{
    public function __construct(
        protected LedgerAggregate $ledger,
        protected TransactionAggregate $transaction,
        protected TransferAggregate $transfer
    ) {
    }

    /**
     * @param  mixed  $account
     */
    public function create(Account|array $account): string
    {
        $workflow = WorkflowStub::make(CreateAccountWorkflow::class);
        return $workflow->execute(__account($account));
    }

    public function destroy(mixed $uuid): void
    {
        $workflow = WorkflowStub::make(DestroyAccountWorkflow::class);
        $workflow->start(__account_uuid($uuid));
    }

    public function deposit(mixed $uuid, mixed $amount): void
    {
        $workflow = WorkflowStub::make(DepositAccountWorkflow::class);
        $workflow->start(__account_uuid($uuid), __money($amount));
    }

    public function withdraw(mixed $uuid, mixed $amount): void
    {
        $workflow = WorkflowStub::make(WithdrawAccountWorkflow::class);
        $workflow->start(__account_uuid($uuid), __money($amount));
    }

    public function createForUser(string $userUuid, string $accountName): string
    {
        $account = new Account(
            name: $accountName,
            userUuid: $userUuid
        );

        return $this->create($account);
    }
}
