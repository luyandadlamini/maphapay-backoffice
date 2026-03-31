<?php

namespace App\Domain\Account\Services;

use App\Domain\Account\Aggregates\LedgerAggregate;
use App\Domain\Account\Aggregates\TransactionAggregate;
use App\Domain\Account\Aggregates\TransferAggregate;
use App\Domain\Account\DataObjects\Account;
use App\Domain\Account\Events\AccountCreated;
use App\Domain\Account\Models\Account as AccountModel;
use App\Domain\Account\Repositories\AccountRepository;
use App\Domain\Account\Workflows\CreateAccountWorkflow;
use App\Domain\Account\Workflows\DepositAccountWorkflow;
use App\Domain\Account\Workflows\DestroyAccountWorkflow;
use App\Domain\Account\Workflows\WithdrawAccountWorkflow;
use Illuminate\Support\Str;
use Workflow\WorkflowStub;

class AccountService
{
    public function __construct(
        protected LedgerAggregate $ledger,
        protected TransactionAggregate $transaction,
        protected TransferAggregate $transfer,
        protected AccountRepository $accountRepository
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

    /**
     * Create account directly without workflow (for admin use).
     */
    public function createDirect(Account|array $account): string
    {
        $accountData = __account($account);
        $uuid = $accountData->getUuid() ?: Str::uuid()->toString();
        $accountDataWithUuid = $accountData->withUuid($uuid);

        // Record the event
        $this->ledger->retrieve($uuid)
            ->createAccount($accountDataWithUuid)
            ->persist();

        // Directly create the account model (projector would do this async via queue)
        $this->accountRepository->create($accountDataWithUuid);

        return $uuid;
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

        return $this->createDirect($account);
    }
}
