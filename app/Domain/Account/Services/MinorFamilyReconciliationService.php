<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Events\MinorFamilyFundingAttemptFailed;
use App\Domain\Account\Events\MinorFamilyFundingAttemptSucceeded;
use App\Domain\Account\Events\MinorFamilyFundingCredited;
use App\Domain\Account\Events\MinorFamilySupportTransferFailed;
use App\Domain\Account\Events\MinorFamilySupportTransferRefunded;
use App\Domain\Account\Events\MinorFamilySupportTransferSucceeded;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Domain\Asset\Models\Asset;
use App\Domain\Shared\Money\MoneyConverter;
use App\Domain\Wallet\Services\WalletOperationsService;
use App\Models\MtnMomoTransaction;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MinorFamilyReconciliationService
{
    public function __construct(
        private readonly WalletOperationsService $walletOps,
        private readonly MinorNotificationService $notifications,
    ) {
    }

    public function reconcile(MtnMomoTransaction $transaction): MinorFamilyReconciliationOutcome
    {
        $fundingAttempt = $this->resolveFundingAttempt($transaction);
        if ($fundingAttempt !== null) {
            return $this->reconcileFundingAttempt($fundingAttempt, $transaction);
        }

        $supportTransfer = $this->resolveSupportTransfer($transaction);
        if ($supportTransfer !== null) {
            return $this->reconcileSupportTransfer($supportTransfer, $transaction);
        }

        return MinorFamilyReconciliationOutcome::UNRESOLVED;
    }

    private function reconcileFundingAttempt(
        MinorFamilyFundingAttempt $attempt,
        MtnMomoTransaction $transaction,
    ): MinorFamilyReconciliationOutcome
    {
        DB::transaction(function () use ($attempt, $transaction): void {
            /** @var MinorFamilyFundingAttempt|null $lockedAttempt */
            $lockedAttempt = MinorFamilyFundingAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->first();
            /** @var MtnMomoTransaction|null $lockedTransaction */
            $lockedTransaction = MtnMomoTransaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->first();

            if ($lockedAttempt === null || $lockedTransaction === null) {
                return;
            }

            if ($lockedTransaction->status === MtnMomoTransaction::STATUS_SUCCESSFUL) {
                $this->creditFundingAttemptIfNeeded($lockedAttempt, $lockedTransaction);

                return;
            }

            if ($lockedTransaction->status === MtnMomoTransaction::STATUS_FAILED) {
                if ($lockedAttempt->status !== MinorFamilyFundingAttempt::STATUS_FAILED) {
                    $lockedAttempt->forceFill([
                        'status' => MinorFamilyFundingAttempt::STATUS_FAILED,
                        'failed_reason' => $lockedAttempt->failed_reason ?? 'provider_failed',
                    ])->save();

                    $this->notifications->notify(
                        minorAccountUuid: $lockedAttempt->minor_account_uuid,
                        type: MinorNotificationService::TYPE_FAMILY_FUNDING_ATTEMPT_FAILED,
                        data: [
                            'funding_attempt_uuid' => $lockedAttempt->id,
                            'funding_link_uuid' => $lockedAttempt->funding_link_uuid,
                            'provider_reference_id' => $lockedAttempt->provider_reference_id,
                            'mtn_momo_transaction_id' => $lockedTransaction->id,
                            'failed_reason' => $lockedAttempt->failed_reason,
                        ],
                        actorUserUuid: $this->resolveActorUserUuidForAttempt($lockedAttempt),
                        targetType: 'minor_family_funding_attempt',
                        targetId: $lockedAttempt->id,
                    );

                    event(new MinorFamilyFundingAttemptFailed(
                        $lockedAttempt->id,
                        $lockedAttempt->funding_link_uuid,
                        $lockedAttempt->minor_account_uuid,
                        (string) ($lockedAttempt->failed_reason ?? 'provider_failed'),
                    ));
                }
            }
        });

        /** @var MinorFamilyFundingAttempt|null $freshAttempt */
        $freshAttempt = MinorFamilyFundingAttempt::query()
            ->whereKey($attempt->id)
            ->first();
        if ($freshAttempt === null) {
            return MinorFamilyReconciliationOutcome::UNRESOLVED;
        }

        if (in_array($freshAttempt->status, [
            MinorFamilyFundingAttempt::STATUS_CREDITED,
            MinorFamilyFundingAttempt::STATUS_FAILED,
        ], true)) {
            return MinorFamilyReconciliationOutcome::RECONCILED;
        }

        return MinorFamilyReconciliationOutcome::UNRESOLVED;
    }

    private function creditFundingAttemptIfNeeded(
        MinorFamilyFundingAttempt $attempt,
        MtnMomoTransaction $transaction,
    ): void {
        if ($attempt->wallet_credited_at !== null && $attempt->status === MinorFamilyFundingAttempt::STATUS_CREDITED) {
            return;
        }

        /** @var Account|null $minorAccount */
        $minorAccount = Account::query()
            ->where('uuid', $attempt->minor_account_uuid)
            ->first();
        if ($minorAccount === null) {
            return;
        }

        /** @var Asset|null $asset */
        $asset = Asset::query()
            ->where('code', $attempt->asset_code)
            ->first();
        if ($asset === null) {
            return;
        }

        if ($attempt->wallet_credited_at === null) {
            $amountMinor = (string) MoneyConverter::forAsset($attempt->amount, $asset);

            try {
                $this->walletOps->deposit(
                    $minorAccount->uuid,
                    $attempt->asset_code,
                    $amountMinor,
                    'minor-family-funding-credit:' . ($transaction->mtn_reference_id ?? $attempt->id),
                    ['mtn_momo_transaction_id' => $transaction->id],
                );
            } catch (Throwable $exception) {
                $attempt->forceFill([
                    'status' => MinorFamilyFundingAttempt::STATUS_SUCCESSFUL_UNCREDITED,
                    'failed_reason' => 'wallet_credit_failed',
                ])->save();

                Log::warning('MinorFamilyReconciliationService: funding attempt wallet credit failed', [
                    'funding_attempt_id' => $attempt->id,
                    'mtn_momo_transaction_id' => $transaction->id,
                    'provider_reference_id' => $attempt->provider_reference_id,
                    'error' => $exception->getMessage(),
                ]);

                return;
            }

            $transaction->forceFill(['wallet_credited_at' => now()])->save();
            $attempt->forceFill(['wallet_credited_at' => now()])->save();

            /** @var MinorFamilyFundingLink|null $link */
            $link = MinorFamilyFundingLink::query()
                ->whereKey($attempt->funding_link_uuid)
                ->lockForUpdate()
                ->first();
            if ($link !== null) {
                $currentCollectedAmount = $this->normaliseNumericAmount($link->collected_amount);
                $attemptAmount = $this->normaliseNumericAmount($attempt->amount);
                if ($currentCollectedAmount === null || $attemptAmount === null) {
                    return;
                }

                $updatedCollectedAmount = $currentCollectedAmount
                    ->plus($attemptAmount)
                    ->toScale(2, RoundingMode::DOWN)
                    ->__toString();
                $link->forceFill([
                    'collected_amount' => $updatedCollectedAmount,
                    'last_funded_at' => now(),
                ])->save();
            }
        }

        if ($attempt->status !== MinorFamilyFundingAttempt::STATUS_CREDITED) {
            $attempt->forceFill([
                'status' => MinorFamilyFundingAttempt::STATUS_CREDITED,
                'failed_reason' => null,
            ])->save();

            $actorUserUuid = $this->resolveActorUserUuidForAttempt($attempt);

            $this->notifications->notify(
                minorAccountUuid: $attempt->minor_account_uuid,
                type: MinorNotificationService::TYPE_FAMILY_FUNDING_ATTEMPT_SUCCEEDED,
                data: [
                    'funding_attempt_uuid' => $attempt->id,
                    'funding_link_uuid' => $attempt->funding_link_uuid,
                    'provider_reference_id' => $attempt->provider_reference_id,
                    'mtn_momo_transaction_id' => $transaction->id,
                ],
                actorUserUuid: $actorUserUuid,
                targetType: 'minor_family_funding_attempt',
                targetId: $attempt->id,
            );

            $this->notifications->notify(
                minorAccountUuid: $attempt->minor_account_uuid,
                type: MinorNotificationService::TYPE_FAMILY_FUNDING_CREDITED,
                data: [
                    'funding_attempt_uuid' => $attempt->id,
                    'funding_link_uuid' => $attempt->funding_link_uuid,
                    'amount' => $attempt->amount,
                    'asset_code' => $attempt->asset_code,
                    'provider_reference_id' => $attempt->provider_reference_id,
                    'mtn_momo_transaction_id' => $transaction->id,
                ],
                actorUserUuid: $actorUserUuid,
                targetType: 'minor_family_funding_attempt',
                targetId: $attempt->id,
            );

            event(new MinorFamilyFundingAttemptSucceeded(
                $attempt->id,
                $attempt->funding_link_uuid,
                $attempt->minor_account_uuid,
                $attempt->provider_reference_id,
            ));

            event(new MinorFamilyFundingCredited(
                $attempt->id,
                $attempt->funding_link_uuid,
                $attempt->minor_account_uuid,
                $attempt->amount,
                $attempt->asset_code,
            ));
        }
    }

    private function reconcileSupportTransfer(
        MinorFamilySupportTransfer $transfer,
        MtnMomoTransaction $transaction,
    ): MinorFamilyReconciliationOutcome {
        DB::transaction(function () use ($transfer, $transaction): void {
            /** @var MinorFamilySupportTransfer|null $lockedTransfer */
            $lockedTransfer = MinorFamilySupportTransfer::query()
                ->whereKey($transfer->id)
                ->lockForUpdate()
                ->first();
            /** @var MtnMomoTransaction|null $lockedTransaction */
            $lockedTransaction = MtnMomoTransaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTransfer === null || $lockedTransaction === null) {
                return;
            }

            if ($lockedTransaction->status === MtnMomoTransaction::STATUS_SUCCESSFUL) {
                if ($lockedTransfer->status !== MinorFamilySupportTransfer::STATUS_SUCCESSFUL) {
                    $lockedTransfer->forceFill([
                        'status' => MinorFamilySupportTransfer::STATUS_SUCCESSFUL,
                        'failed_reason' => null,
                    ])->save();

                    $this->notifications->notify(
                        minorAccountUuid: $lockedTransfer->minor_account_uuid,
                        type: MinorNotificationService::TYPE_FAMILY_SUPPORT_TRANSFER_SUCCEEDED,
                        data: [
                            'family_support_transfer_uuid' => $lockedTransfer->id,
                            'provider_reference_id' => $lockedTransfer->provider_reference_id,
                            'mtn_momo_transaction_id' => $lockedTransaction->id,
                        ],
                        actorUserUuid: $lockedTransfer->actor_user_uuid,
                        targetType: 'minor_family_support_transfer',
                        targetId: $lockedTransfer->id,
                    );

                    event(new MinorFamilySupportTransferSucceeded(
                        $lockedTransfer->id,
                        $lockedTransfer->minor_account_uuid,
                        $lockedTransfer->provider_reference_id,
                    ));
                }

                return;
            }

            if ($lockedTransaction->status !== MtnMomoTransaction::STATUS_FAILED) {
                return;
            }

            if ($lockedTransfer->status === MinorFamilySupportTransfer::STATUS_FAILED_REFUNDED) {
                return;
            }

            $refunded = false;
            $failedReason = 'provider_failed';

            if ($lockedTransaction->wallet_debited_at !== null && $lockedTransaction->wallet_refunded_at === null) {
                /** @var Asset|null $asset */
                $asset = Asset::query()
                    ->where('code', $lockedTransfer->asset_code)
                    ->first();

                if ($asset !== null) {
                    $amountMinor = (string) MoneyConverter::forAsset($lockedTransfer->amount, $asset);
                    try {
                        $this->walletOps->deposit(
                            $lockedTransfer->source_account_uuid,
                            $lockedTransfer->asset_code,
                            $amountMinor,
                            'minor-family-support-refund:' . ($lockedTransaction->mtn_reference_id ?? $lockedTransfer->id),
                            ['mtn_momo_transaction_id' => $lockedTransaction->id],
                        );

                        $lockedTransaction->forceFill(['wallet_refunded_at' => now()])->save();
                        $lockedTransfer->forceFill(['wallet_refunded_at' => now()])->save();
                        $refunded = true;
                    } catch (Throwable $exception) {
                        $failedReason = 'wallet_refund_failed';

                        Log::warning('MinorFamilyReconciliationService: support transfer wallet refund failed', [
                            'support_transfer_id' => $lockedTransfer->id,
                            'mtn_momo_transaction_id' => $lockedTransaction->id,
                            'provider_reference_id' => $lockedTransfer->provider_reference_id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }

            $nextStatus = $lockedTransaction->wallet_refunded_at !== null
                ? MinorFamilySupportTransfer::STATUS_FAILED_REFUNDED
                : MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED;

            $shouldPersistFailureState = $lockedTransfer->status !== $nextStatus
                || ($nextStatus === MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED
                    && $lockedTransfer->failed_reason !== $failedReason);

            if ($shouldPersistFailureState) {
                $lockedTransfer->forceFill([
                    'status' => $nextStatus,
                    'failed_reason' => $failedReason,
                ])->save();

                $this->notifications->notify(
                    minorAccountUuid: $lockedTransfer->minor_account_uuid,
                    type: MinorNotificationService::TYPE_FAMILY_SUPPORT_TRANSFER_FAILED,
                    data: [
                        'family_support_transfer_uuid' => $lockedTransfer->id,
                        'provider_reference_id' => $lockedTransfer->provider_reference_id,
                        'mtn_momo_transaction_id' => $lockedTransaction->id,
                            'failed_reason' => $failedReason,
                    ],
                    actorUserUuid: $lockedTransfer->actor_user_uuid,
                    targetType: 'minor_family_support_transfer',
                    targetId: $lockedTransfer->id,
                );

                event(new MinorFamilySupportTransferFailed(
                    $lockedTransfer->id,
                    $lockedTransfer->minor_account_uuid,
                    $failedReason,
                ));
            }

            if ($refunded || $lockedTransaction->wallet_refunded_at !== null) {
                $lockedTransfer->forceFill([
                    'status' => MinorFamilySupportTransfer::STATUS_FAILED_REFUNDED,
                ])->save();

                $this->notifications->notify(
                    minorAccountUuid: $lockedTransfer->minor_account_uuid,
                    type: MinorNotificationService::TYPE_FAMILY_SUPPORT_TRANSFER_REFUNDED,
                    data: [
                        'family_support_transfer_uuid' => $lockedTransfer->id,
                        'refunded_to_account_uuid' => $lockedTransfer->source_account_uuid,
                        'amount' => $lockedTransfer->amount,
                        'asset_code' => $lockedTransfer->asset_code,
                        'provider_reference_id' => $lockedTransfer->provider_reference_id,
                        'mtn_momo_transaction_id' => $lockedTransaction->id,
                    ],
                    actorUserUuid: $lockedTransfer->actor_user_uuid,
                    targetType: 'minor_family_support_transfer',
                    targetId: $lockedTransfer->id,
                );

                event(new MinorFamilySupportTransferRefunded(
                    $lockedTransfer->id,
                    $lockedTransfer->minor_account_uuid,
                    $lockedTransfer->source_account_uuid,
                    $lockedTransfer->amount,
                    $lockedTransfer->asset_code,
                ));
            }
        });

        /** @var MinorFamilySupportTransfer|null $freshTransfer */
        $freshTransfer = MinorFamilySupportTransfer::query()
            ->whereKey($transfer->id)
            ->first();
        if ($freshTransfer === null) {
            return MinorFamilyReconciliationOutcome::UNRESOLVED;
        }

        if (in_array($freshTransfer->status, [
            MinorFamilySupportTransfer::STATUS_SUCCESSFUL,
            MinorFamilySupportTransfer::STATUS_FAILED_REFUNDED,
        ], true)) {
            return MinorFamilyReconciliationOutcome::RECONCILED;
        }

        return MinorFamilyReconciliationOutcome::UNRESOLVED;
    }

    private function resolveActorUserUuidForAttempt(MinorFamilyFundingAttempt $attempt): ?string
    {
        /** @var MinorFamilyFundingLink|null $link */
        $link = MinorFamilyFundingLink::query()
            ->whereKey($attempt->funding_link_uuid)
            ->first();

        return $link?->created_by_user_uuid;
    }

    private function resolveFundingAttempt(MtnMomoTransaction $transaction): ?MinorFamilyFundingAttempt
    {
        $contextType = (string) ($transaction->getAttribute('context_type') ?? '');
        $contextUuid = (string) ($transaction->getAttribute('context_uuid') ?? '');

        if ($contextType === MinorFamilyFundingAttempt::class && $contextUuid !== '') {
            /** @var MinorFamilyFundingAttempt|null $resolved */
            $resolved = MinorFamilyFundingAttempt::query()
                ->whereKey($contextUuid)
                ->first();

            if ($resolved !== null) {
                if (! $this->hasTenantContext($resolved->tenant_id)) {
                    Log::warning('MinorFamilyReconciliationService: missing tenant context for phase9 funding attempt', [
                        'funding_attempt_id' => $resolved->id,
                        'mtn_momo_transaction_id' => $transaction->id,
                        'context_uuid' => $contextUuid,
                    ]);

                    return null;
                }

                return $resolved;
            }
        }

        /** @var MinorFamilyFundingAttempt|null $resolvedByLink */
        $resolvedByLink = MinorFamilyFundingAttempt::query()
            ->where(function ($query) use ($transaction): void {
                $query->where('mtn_momo_transaction_id', $transaction->id);
                if (is_string($transaction->mtn_reference_id) && $transaction->mtn_reference_id !== '') {
                    $query->orWhere('provider_reference_id', $transaction->mtn_reference_id);
                }
            })
            ->first();

        if ($resolvedByLink !== null && ! $this->hasTenantContext($resolvedByLink->tenant_id)) {
            Log::warning('MinorFamilyReconciliationService: missing tenant context for phase9 funding attempt', [
                'funding_attempt_id' => $resolvedByLink->id,
                'mtn_momo_transaction_id' => $transaction->id,
                'context_uuid' => $contextUuid,
            ]);

            return null;
        }

        return $resolvedByLink;
    }

    private function resolveSupportTransfer(MtnMomoTransaction $transaction): ?MinorFamilySupportTransfer
    {
        $contextType = (string) ($transaction->getAttribute('context_type') ?? '');
        $contextUuid = (string) ($transaction->getAttribute('context_uuid') ?? '');

        if ($contextType === MinorFamilySupportTransfer::class && $contextUuid !== '') {
            /** @var MinorFamilySupportTransfer|null $resolved */
            $resolved = MinorFamilySupportTransfer::query()
                ->whereKey($contextUuid)
                ->first();

            if ($resolved !== null) {
                if (! $this->hasTenantContext($resolved->tenant_id)) {
                    Log::warning('MinorFamilyReconciliationService: missing tenant context for phase9 support transfer', [
                        'support_transfer_id' => $resolved->id,
                        'mtn_momo_transaction_id' => $transaction->id,
                        'context_uuid' => $contextUuid,
                    ]);

                    return null;
                }

                return $resolved;
            }
        }

        /** @var MinorFamilySupportTransfer|null $resolvedByLink */
        $resolvedByLink = MinorFamilySupportTransfer::query()
            ->where(function ($query) use ($transaction): void {
                $query->where('mtn_momo_transaction_id', $transaction->id);
                if (is_string($transaction->mtn_reference_id) && $transaction->mtn_reference_id !== '') {
                    $query->orWhere('provider_reference_id', $transaction->mtn_reference_id);
                }
            })
            ->first();

        if ($resolvedByLink !== null && ! $this->hasTenantContext($resolvedByLink->tenant_id)) {
            Log::warning('MinorFamilyReconciliationService: missing tenant context for phase9 support transfer', [
                'support_transfer_id' => $resolvedByLink->id,
                'mtn_momo_transaction_id' => $transaction->id,
                'context_uuid' => $contextUuid,
            ]);

            return null;
        }

        return $resolvedByLink;
    }

    private function hasTenantContext(?string $tenantId): bool
    {
        return is_string($tenantId) && trim($tenantId) !== '';
    }

    private function normaliseNumericAmount(?string $amount): ?BigDecimal
    {
        if (! is_string($amount)) {
            return null;
        }

        $amount = trim($amount);

        if ($amount === '' || preg_match('/^-?\d+(?:\.\d+)?$/', $amount) !== 1) {
            return null;
        }

        return BigDecimal::of($amount);
    }
}
