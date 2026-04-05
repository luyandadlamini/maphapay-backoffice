<?php

declare(strict_types=1);

namespace App\Domain\FundManagement\Services;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\TransactionProjection;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Workflows\AssetDepositWorkflow;
use App\Domain\FundManagement\Models\FundAdjustmentJournal;
use App\Domain\FundManagement\Models\TestFunding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Workflow\WorkflowStub;

class FundManagementService
{
    public function fundAccount(
        Account $account,
        string $assetCode,
        int $amountInSmallestUnit,
        string $reason,
        ?string $notes = null,
        ?User $performedBy = null
    ): TestFunding {
        if ($amountInSmallestUnit <= 0) {
            throw new InvalidArgumentException('Amount must be positive');
        }

        $asset = Asset::where('code', $assetCode)->firstOrFail();

        $testFunding = TestFunding::create([
            'uuid'             => Str::uuid()->toString(),
            'account_uuid'     => $account->uuid,
            'user_uuid'        => $account->user_uuid,
            'asset_code'       => $assetCode,
            'amount'           => $amountInSmallestUnit,
            'amount_formatted' => $asset->fromSmallestUnit($amountInSmallestUnit),
            'reason'           => $reason,
            'notes'            => $notes,
            'status'           => TestFunding::STATUS_PENDING,
            'performed_by'     => $performedBy?->email ?? 'system',
            'performed_at'     => now(),
        ]);

        try {
            DB::beginTransaction();

            $workflow = WorkflowStub::make(AssetDepositWorkflow::class);
            $workflow->execute(
                AccountUuid::fromString($account->uuid),
                $assetCode,
                new Money($amountInSmallestUnit),
                "Test funding: {$reason}"
            );

            $testFunding->update([
                'status'       => TestFunding::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            $this->recordTransaction(
                $account,
                'deposit',
                $assetCode,
                $amountInSmallestUnit,
                "Test funding: {$reason}",
                $testFunding->uuid
            );

            DB::commit();

        } catch (Throwable $e) {
            DB::rollBack();

            $testFunding->update([
                'status' => TestFunding::STATUS_FAILED,
                'notes'  => ($testFunding->notes ? $testFunding->notes . "\n" : '') . "Error: {$e->getMessage()}",
            ]);

            throw $e;
        }

        return $testFunding;
    }

    public function transferBetweenAccounts(
        Account $fromAccount,
        Account $toAccount,
        string $assetCode,
        int $amountInSmallestUnit,
        string $reason,
        ?string $notes = null,
        ?User $performedBy = null
    ): void {
        if ($amountInSmallestUnit <= 0) {
            throw new InvalidArgumentException('Amount must be positive');
        }

        if ($fromAccount->uuid === $toAccount->uuid) {
            throw new InvalidArgumentException('Cannot transfer to the same account');
        }

        if ($fromAccount->frozen) {
            throw new RuntimeException('Source account is frozen');
        }

        if ($toAccount->frozen) {
            throw new RuntimeException('Destination account is frozen');
        }

        $transferId = 'transfer_' . Str::uuid()->toString();

        DB::beginTransaction();

        try {
            $fromUuid = AccountUuid::fromString($fromAccount->uuid);
            $toUuid = AccountUuid::fromString($toAccount->uuid);

            $workflow = WorkflowStub::make(\App\Domain\Asset\Workflows\AssetTransferWorkflow::class);
            $workflow->execute(
                $fromUuid,
                $toUuid,
                $assetCode,
                new Money($amountInSmallestUnit),
                $reason
            );

            $this->recordTransaction(
                $fromAccount,
                'transfer_out',
                $assetCode,
                $amountInSmallestUnit,
                "Transfer to {$toAccount->name}: {$reason}",
                $transferId
            );

            $this->recordTransaction(
                $toAccount,
                'transfer_in',
                $assetCode,
                $amountInSmallestUnit,
                "Transfer from {$fromAccount->name}: {$reason}",
                $transferId
            );

            DB::commit();

        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function adjustBalance(
        Account $account,
        string $assetCode,
        int $amountInSmallestUnit,
        string $reasonCategory,
        string $description,
        ?User $performedBy = null,
        ?User $approvedBy = null
    ): FundAdjustmentJournal {
        if ($amountInSmallestUnit === 0) {
            throw new InvalidArgumentException('Adjustment amount cannot be zero');
        }

        $isCredit = $amountInSmallestUnit > 0;
        $absAmount = abs($amountInSmallestUnit);

        $adjustment = FundAdjustmentJournal::create([
            'uuid'              => Str::uuid()->toString(),
            'account_uuid'      => $account->uuid,
            'user_uuid'         => $account->user_uuid,
            'asset_code'        => $assetCode,
            'adjustment_amount' => $amountInSmallestUnit,
            'adjustment_type'   => $isCredit ? FundAdjustmentJournal::TYPE_CREDIT : FundAdjustmentJournal::TYPE_DEBIT,
            'reason_category'   => $reasonCategory,
            'description'       => $description,
            'performed_by'      => $performedBy?->email ?? 'system',
            'approved_by'       => $approvedBy?->email,
            'performed_at'      => now(),
            'approved_at'       => $approvedBy ? now() : null,
            'status'            => FundAdjustmentJournal::STATUS_PENDING,
        ]);

        DB::beginTransaction();

        try {
            $workflow = WorkflowStub::make(AssetDepositWorkflow::class);
            $workflow->execute(
                AccountUuid::fromString($account->uuid),
                $assetCode,
                new Money($absAmount),
                "Balance adjustment ({$reasonCategory}): {$description}"
            );

            $this->recordTransaction(
                $account,
                $isCredit ? 'adjustment_credit' : 'adjustment_debit',
                $assetCode,
                $absAmount,
                "Adjustment ({$reasonCategory}): {$description}",
                $adjustment->uuid
            );

            $adjustment->update([
                'status' => FundAdjustmentJournal::STATUS_COMPLETED,
            ]);

            DB::commit();

        } catch (Throwable $e) {
            DB::rollBack();

            $adjustment->update([
                'status' => FundAdjustmentJournal::STATUS_FAILED,
            ]);

            throw $e;
        }

        return $adjustment;
    }

    public function getTreasuryBalance(string $assetCode): int
    {
        $snapshot = \App\Domain\Treasury\Models\TreasurySnapshot::where('asset_code', $assetCode)
            ->latest()
            ->first();

        return $snapshot?->balance ?? 0;
    }

    public function getTreasuryBalances(): array
    {
        $assets = Asset::active()->get();
        $balances = [];

        foreach ($assets as $asset) {
            $balance = $this->getTreasuryBalance($asset->code);
            $balances[$asset->code] = [
                'code'      => $asset->code,
                'name'      => $asset->name,
                'type'      => $asset->type,
                'precision' => $asset->precision,
                'balance'   => $balance,
                'formatted' => $asset->formatAmount($balance),
            ];
        }

        return $balances;
    }

    public function getTestFundingHistory(?string $accountUuid = null, int $limit = 50): array
    {
        $query = TestFunding::query()->orderBy('created_at', 'desc');

        if ($accountUuid) {
            $query->where('account_uuid', $accountUuid);
        }

        return $query->limit($limit)->get()->toArray();
    }

    public function getAdjustmentHistory(?string $accountUuid = null, int $limit = 50): array
    {
        $query = FundAdjustmentJournal::query()->orderBy('created_at', 'desc');

        if ($accountUuid) {
            $query->where('account_uuid', $accountUuid);
        }

        return $query->limit($limit)->get()->toArray();
    }

    public function searchAccount(string $identifier): ?Account
    {
        return Account::where('uuid', $identifier)
            ->orWhere('name', 'like', "%{$identifier}%")
            ->first();
    }

    protected function recordTransaction(
        Account $account,
        string $type,
        string $assetCode,
        int $amount,
        string $description,
        string $reference
    ): TransactionProjection {
        return TransactionProjection::create([
            'uuid'         => Str::uuid()->toString(),
            'account_uuid' => $account->uuid,
            'asset_code'   => $assetCode,
            'amount'       => $amount,
            'type'         => $type,
            'description'  => $description,
            'reference'    => $reference,
            'status'       => 'completed',
            'metadata'     => [
                'source'      => 'fund_management',
                'recorded_at' => now()->toISOString(),
            ],
        ]);
    }
}
