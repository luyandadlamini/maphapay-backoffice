<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Aggregates\AssetTransactionAggregate;
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
        $accountData = __account($account);
        $result = $workflow->execute($accountData);

        if (is_string($result) && $result !== '') {
            return $result;
        }

        return $this->createDirect($accountData);
    }

    /**
     * Create account directly without workflow (for admin use).
     *
     * @param  Account|array<string, mixed>  $account
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
        $accountUuid = (string) __account_uuid($uuid);
        $assetCode = config('banking.default_currency', 'SZL');
        $moneyAmount = __money($amount);

        AssetTransactionAggregate::retrieve($accountUuid)
            ->credit($assetCode, $moneyAmount->getAmount())
            ->persist();

        return $this->recordDepositTransaction($accountUuid, $moneyAmount->getAmount(), $description);
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
        $accountUuid = (string) __account_uuid($uuid);
        $assetCode = config('banking.default_currency', 'SZL');
        $moneyAmount = __money($amount);

        AssetTransactionAggregate::retrieve($accountUuid)
            ->debit($assetCode, $moneyAmount->getAmount())
            ->persist();

        return $this->recordWithdrawTransaction($accountUuid, $moneyAmount->getAmount(), $description);
    }

    protected function recordDepositTransaction(mixed $uuid, mixed $amount, string $description): string
    {
        $reference = Str::uuid()->toString();

        \App\Domain\Account\Models\TransactionProjection::create([
            'uuid'         => $reference,
            'account_uuid' => $uuid,
            'asset_code'   => config('banking.default_currency', 'SZL'),
            'amount'       => $amount,
            'type'         => 'deposit',
            'description'  => $description,
            'reference'    => $reference,
            'status'       => 'completed',
            'hash'         => hash('sha3-512', "{$uuid}:{$amount}:{$reference}"),
        ]);

        return $reference;
    }

    protected function recordWithdrawTransaction(mixed $uuid, mixed $amount, string $description): string
    {
        $reference = Str::uuid()->toString();

        \App\Domain\Account\Models\TransactionProjection::create([
            'uuid'         => $reference,
            'account_uuid' => $uuid,
            'asset_code'   => config('banking.default_currency', 'SZL'),
            'amount'       => -abs($amount),
            'type'         => 'withdrawal',
            'description'  => $description,
            'reference'    => $reference,
            'status'       => 'completed',
            'hash'         => hash('sha3-512', "{$uuid}:{$amount}:{$reference}"),
        ]);

        return $reference;
    }

    /**
     * Freeze a wallet account, preventing all transactions.
     */
    public function freeze(mixed $uuid, string $reason = 'backoffice_action', ?string $authorizedBy = null): void
    {
        $accountUuid = (string) __account_uuid($uuid);

        LedgerAggregate::retrieve($accountUuid)
            ->freezeAccount(reason: $reason, authorizedBy: $authorizedBy)
            ->persist();
    }

    /**
     * Unfreeze a wallet account, restoring transaction capability.
     */
    public function unfreeze(mixed $uuid, string $reason = 'backoffice_action', ?string $authorizedBy = null): void
    {
        $accountUuid = (string) __account_uuid($uuid);

        LedgerAggregate::retrieve($accountUuid)
            ->unfreezeAccount(reason: $reason, authorizedBy: $authorizedBy)
            ->persist();
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
