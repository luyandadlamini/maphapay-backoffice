<?php

declare(strict_types=1);

namespace App\Domain\GroupSavings\Services;

use App\Domain\Account\DataObjects\AccountUuid;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Exceptions\NotEnoughFunds;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Aggregates\AssetTransactionAggregate;
use App\Domain\Asset\Models\Asset;
use App\Models\GroupPocket;
use App\Models\GroupPocketContribution;
use App\Models\GroupPocketWithdrawalRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class GroupPocketTransferService
{
    private const ASSET_CODE = 'SZL';

    /**
     * Deduct from user's wallet and add to the group pocket.
     * Creates/increments the per-member contribution row.
     *
     * @throws NotEnoughFunds
     * @throws InvalidArgumentException
     */
    public function deposit(User $user, GroupPocket $pocket, float $amountMajor): GroupPocket
    {
        $rounded = number_format($amountMajor, 2, '.', '');

        if ((float) $rounded <= 0) {
            throw new InvalidArgumentException('Amount must be greater than zero');
        }

        return DB::transaction(function () use ($user, $pocket, $rounded): GroupPocket {
            $lockedPocket = GroupPocket::query()
                ->where('id', $pocket->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPocket->status === GroupPocket::STATUS_CLOSED) {
                throw new InvalidArgumentException('Pocket is closed');
            }

            if ($lockedPocket->wouldExceedRegulatoryMax($rounded)) {
                throw new InvalidArgumentException('This pocket has reached the regulatory maximum of E100,000');
            }

            $account     = $this->requireAccount($user);
            $asset       = $this->requireAsset();
            $amountMinor = $asset->toSmallestUnit((float) $rounded);

            $accountBalance = AccountBalance::query()
                ->where('account_uuid', $account->uuid)
                ->where('asset_code', $asset->code)
                ->lockForUpdate()
                ->first();

            if (! $accountBalance || ! $accountBalance->hasSufficientBalance($amountMinor)) {
                throw new NotEnoughFunds('Insufficient wallet balance.');
            }

            AssetTransactionAggregate::retrieve((string) Str::uuid())
                ->debit(
                    accountUuid: new AccountUuid($account->uuid),
                    assetCode: $asset->code,
                    money: new Money($amountMinor),
                    description: "Group pocket deposit: {$lockedPocket->name}",
                    metadata: [
                        'source'          => 'group_pocket_deposit',
                        'group_pocket_id' => $lockedPocket->id,
                        'direction'       => 'to_pocket',
                    ]
                )
                ->persist();

            $lockedPocket->addFunds($rounded);

            // Upsert contribution row — firstOrCreate ensures the row exists,
            // then increment atomically adds to it (safe inside the lockForUpdate transaction).
            GroupPocketContribution::firstOrCreate(
                ['group_pocket_id' => $lockedPocket->id, 'user_id' => $user->id],
                ['amount' => 0],
            )->increment('amount', (float) $rounded);

            Cache::forget("maphapay.dashboard.balance.{$user->id}");

            return $lockedPocket->fresh() ?? $lockedPocket;
        });
    }

    /**
     * Admin approves a withdrawal: credits funds to the requester's wallet,
     * deducts from the pocket's current_amount.
     * Contribution rows are NOT decremented (they track cumulative deposits).
     *
     * @throws InvalidArgumentException
     */
    public function approveWithdrawal(GroupPocketWithdrawalRequest $withdrawalRequest, User $admin): GroupPocketWithdrawalRequest
    {
        $rounded = number_format((float) $withdrawalRequest->amount, 2, '.', '');

        return DB::transaction(function () use ($withdrawalRequest, $admin, $rounded): GroupPocketWithdrawalRequest {
            $lockedRequest = GroupPocketWithdrawalRequest::query()
                ->where('id', $withdrawalRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== GroupPocketWithdrawalRequest::STATUS_PENDING) {
                throw new InvalidArgumentException('Withdrawal request is no longer pending');
            }

            $lockedPocket = GroupPocket::query()
                ->where('id', $lockedRequest->group_pocket_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (bccomp((string) $lockedPocket->current_amount, $rounded, 2) < 0) {
                throw new InvalidArgumentException('Insufficient funds in pocket');
            }

            $requester   = User::findOrFail($lockedRequest->requested_by);
            $account     = $this->requireAccount($requester);
            $asset       = $this->requireAsset();
            $amountMinor = $asset->toSmallestUnit((float) $rounded);

            AssetTransactionAggregate::retrieve((string) Str::uuid())
                ->credit(
                    accountUuid: new AccountUuid($account->uuid),
                    assetCode: $asset->code,
                    money: new Money($amountMinor),
                    description: "Group pocket withdrawal: {$lockedPocket->name}",
                    metadata: [
                        'source'                => 'group_pocket_withdrawal',
                        'group_pocket_id'       => $lockedPocket->id,
                        'withdrawal_request_id' => $lockedRequest->id,
                        'direction'             => 'from_pocket',
                    ]
                )
                ->persist();

            $lockedPocket->deductFunds($rounded);

            $lockedRequest->update([
                'status'      => GroupPocketWithdrawalRequest::STATUS_APPROVED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            Cache::forget("maphapay.dashboard.balance.{$requester->id}");

            return $lockedRequest->fresh() ?? $lockedRequest;
        });
    }

    /**
     * Refund a single member's proportional share when they leave the group.
     * Proportional formula: refund = min(their_contribution, current_amount × their_share_fraction)
     * Cancels their pending withdrawal requests.
     */
    public function refundMemberContributions(GroupPocket $pocket, User $user): void
    {
        $contribution = GroupPocketContribution::query()
            ->where('group_pocket_id', $pocket->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $contribution || (float) $contribution->amount <= 0) {
            return;
        }

        DB::transaction(function () use ($pocket, $user, $contribution): void {
            $lockedPocket = GroupPocket::query()
                ->where('id', $pocket->id)
                ->lockForUpdate()
                ->firstOrFail();

            $totalContributions = (float) GroupPocketContribution::query()
                ->where('group_pocket_id', $pocket->id)
                ->sum('amount');

            $currentAmount = (float) $lockedPocket->current_amount;
            $memberAmount  = (float) $contribution->amount;

            if ($totalContributions <= 0 || $currentAmount <= 0) {
                $contribution->update(['amount' => 0]);

                return;
            }

            $share  = $memberAmount / $totalContributions;
            $refund = number_format(min($memberAmount, $currentAmount * $share), 2, '.', '');

            if ((float) $refund > 0) {
                $account     = $this->requireAccount($user);
                $asset       = $this->requireAsset();
                $amountMinor = $asset->toSmallestUnit((float) $refund);

                AssetTransactionAggregate::retrieve((string) Str::uuid())
                    ->credit(
                        accountUuid: new AccountUuid($account->uuid),
                        assetCode: $asset->code,
                        money: new Money($amountMinor),
                        description: "Group pocket exit refund: {$lockedPocket->name}",
                        metadata: [
                            'source'          => 'group_pocket_exit_refund',
                            'group_pocket_id' => $lockedPocket->id,
                        ]
                    )
                    ->persist();

                $lockedPocket->deductFunds($refund);
                Cache::forget("maphapay.dashboard.balance.{$user->id}");
            }

            $contribution->update(['amount' => 0]);

            // Cancel any pending withdrawal requests from this user for this pocket
            GroupPocketWithdrawalRequest::query()
                ->where('group_pocket_id', $pocket->id)
                ->where('requested_by', $user->id)
                ->where('status', GroupPocketWithdrawalRequest::STATUS_PENDING)
                ->update(['status' => GroupPocketWithdrawalRequest::STATUS_CANCELLED]);
        });
    }

    /**
     * Refund all members proportionally — used when an admin closes a pocket
     * or when a group is deleted (called from ThreadGroupSavingsObserver).
     */
    public function refundAllContributions(GroupPocket $pocket): void
    {
        $contributions = GroupPocketContribution::query()
            ->where('group_pocket_id', $pocket->id)
            ->where('amount', '>', 0)
            ->get();

        if ($contributions->isEmpty()) {
            return;
        }

        $totalContributions = $contributions->sum(fn ($c) => (float) $c->amount);
        $currentAmount      = (float) ($pocket->fresh() ?? $pocket)->current_amount;

        if ($currentAmount <= 0) {
            $contributions->each(fn ($c) => $c->update(['amount' => 0]));

            return;
        }

        foreach ($contributions as $contribution) {
            $user = User::find($contribution->user_id);

            if (! $user) {
                continue;
            }

            $share  = (float) $contribution->amount / $totalContributions;
            $refund = number_format(min((float) $contribution->amount, $currentAmount * $share), 2, '.', '');

            if ((float) $refund > 0) {
                $account = Account::query()->where('user_uuid', $user->uuid)->orderBy('id')->first();

                $assetCode = (string) config('banking.default_currency', self::ASSET_CODE);
                $asset     = $account !== null ? Asset::query()->where('code', $assetCode)->first() : null;

                if ($account !== null && $asset !== null) {
                    $amountMinor = $asset->toSmallestUnit((float) $refund);

                    AssetTransactionAggregate::retrieve((string) Str::uuid())
                        ->credit(
                            accountUuid: new AccountUuid($account->uuid),
                            assetCode: $assetCode,
                            money: new Money($amountMinor),
                            description: "Group pocket refund: {$pocket->name}",
                            metadata: [
                                'source'          => 'group_pocket_refund',
                                'group_pocket_id' => $pocket->id,
                            ]
                        )
                        ->persist();

                    Cache::forget("maphapay.dashboard.balance.{$user->id}");
                }
            }

            $contribution->update(['amount' => 0]);
        }

        // Cancel all pending withdrawal requests
        GroupPocketWithdrawalRequest::query()
            ->where('group_pocket_id', $pocket->id)
            ->where('status', GroupPocketWithdrawalRequest::STATUS_PENDING)
            ->update(['status' => GroupPocketWithdrawalRequest::STATUS_CANCELLED]);
    }

    private function requireAccount(User $user): Account
    {
        $account = Account::query()->where('user_uuid', $user->uuid)->orderBy('id')->first();

        if (! $account) {
            throw new InvalidArgumentException('Wallet account not found');
        }

        return $account;
    }

    private function requireAsset(): Asset
    {
        $assetCode = (string) config('banking.default_currency', self::ASSET_CODE);
        $asset     = Asset::query()->where('code', $assetCode)->first();

        if (! $asset) {
            throw new InvalidArgumentException('Unsupported wallet currency');
        }

        return $asset;
    }
}
