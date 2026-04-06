<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Aggregates\LedgerAggregate;
use App\Domain\Account\Aggregates\TransactionAggregate;
use App\Domain\Account\Aggregates\TransferAggregate;
use App\Domain\Account\DataObjects\Account;
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

    /**
     * Deposit directly without workflow (for admin use).
     */
    public function depositDirect(mixed $uuid, mixed $amount, string $description = 'Admin deposit'): string
    {
        $assetCode = config('banking.default_currency', 'SZL');

        // Update the AccountBalance directly
        $balance = \App\Domain\Account\Models\AccountBalance::firstOrCreate(
            [
                'account_uuid' => $uuid,
                'asset_code'   => $assetCode,
            ],
            [
                'balance' => 0,
            ]
        );
        $balance->balance += $amount;
        $balance->save();

        return $this->recordDepositTransaction($uuid, $amount, $description);
    }

    public function withdraw(mixed $uuid, mixed $amount): void
    {
        $workflow = WorkflowStub::make(WithdrawAccountWorkflow::class);
        $workflow->start(__account_uuid($uuid), __money($amount));
    }

    /**
     * Withdraw directly without workflow (for admin use).
     */
    public function withdrawDirect(mixed $uuid, mixed $amount, string $description = 'Admin withdrawal'): string
    {
        $assetCode = config('banking.default_currency', 'SZL');

        // Record the event
        $transactionAggregate = $this->transaction->retrieve($uuid);
        $transactionAggregate->debit(__money($amount))->persist();

        // Update the AccountBalance directly
        $balance = \App\Domain\Account\Models\AccountBalance::firstOrCreate(
            [
                'account_uuid' => $uuid,
                'asset_code'   => $assetCode,
            ],
            [
                'balance' => 0,
            ]
        );
        $balance->balance -= $amount;
        $balance->save();

        return $this->recordWithdrawTransaction($uuid, $amount, $description);
    }

    protected function recordDepositTransaction(mixed $uuid, mixed $amount, string $description): string
    {
        $reference = 'dep_' . Str::uuid()->toString();

        \App\Domain\Account\Models\TransactionProjection::create([
            'uuid'         => $reference,
            'account_uuid' => $uuid,
            'asset_code'   => config('banking.default_currency', 'SZL'),
            'amount'       => $amount,
            'type'         => 'deposit',
            'description'  => $description,
            'reference'    => $reference,
            'status'       => 'completed',
        ]);

        return $reference;
    }

    protected function recordWithdrawTransaction(mixed $uuid, mixed $amount, string $description): string
    {
        $reference = 'wd_' . Str::uuid()->toString();

        \App\Domain\Account\Models\TransactionProjection::create([
            'uuid'         => $reference,
            'account_uuid' => $uuid,
            'asset_code'   => config('banking.default_currency', 'SZL'),
            'amount'       => -abs($amount),
            'type'         => 'withdrawal',
            'description'  => $description,
            'reference'    => $reference,
            'status'       => 'completed',
        ]);

        return $reference;
    }

    /**
     * Freeze a wallet account, preventing all transactions.
     * TODO (Task 3.1): Emit AccountFrozen domain event via LedgerAggregate for full audit trail.
     */
    public function freeze(mixed $uuid): void
    {
        $accountUuid = __account_uuid($uuid);

        \App\Domain\Account\Models\Account::where('uuid', $accountUuid)
            ->update(['frozen' => true]);
    }

    /**
     * Unfreeze a wallet account, restoring transaction capability.
     * TODO (Task 3.1): Emit AccountUnfrozen domain event via LedgerAggregate for full audit trail.
     */
    public function unfreeze(mixed $uuid): void
    {
        $accountUuid = __account_uuid($uuid);

        \App\Domain\Account\Models\Account::where('uuid', $accountUuid)
            ->update(['frozen' => false]);
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
