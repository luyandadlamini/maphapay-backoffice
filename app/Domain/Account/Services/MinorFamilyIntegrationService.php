<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Events\MinorFamilyFundingLinkCreated;
use App\Domain\Account\Events\MinorFamilyFundingLinkExpired;
use App\Domain\Account\Events\MinorFamilyFundingAttemptInitiated;
use App\Domain\Account\Events\MinorFamilySupportTransferInitiated;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Models\MinorFamilySupportTransfer;
use App\Domain\MtnMomo\Services\MtnMomoFamilyFundingAdapter;
use App\Domain\Shared\OperationRecord\OperationRecord;
use App\Domain\Shared\OperationRecord\OperationRecordService;
use App\Models\MtnMomoTransaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MinorFamilyIntegrationService
{
    public function __construct(
        private readonly MinorAccountAccessService $accessService,
        private readonly MinorFamilyFundingPolicy $fundingPolicy,
        private readonly MtnMomoFamilyFundingAdapter $fundingAdapter,
        private readonly MinorNotificationService $notifications,
        private readonly OperationRecordService $operationRecords,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createFundingLink(
        User $actor,
        Account $minorAccount,
        array $attributes,
    ): MinorFamilyFundingLink {
        $amountMode = trim((string) ($attributes['amount_mode'] ?? ''));
        $fixedAmount = $this->nullableStringValue($attributes, 'fixed_amount');
        $targetAmount = $this->nullableStringValue($attributes, 'target_amount');
        $providerOptions = $this->normaliseProviderOptions($attributes['provider_options'] ?? null);
        $expiresAt = $this->nullableDateTimeValue($attributes, 'expires_at');
        $requestedAccountUuid = $this->nullableStringValue($attributes, 'created_by_account_uuid');
        $idempotencyKey = $this->nullableStringValue($attributes, 'idempotency_key');

        if ($idempotencyKey === null) {
            $actingAccount = $this->accessService->authorizeGuardian(
                $actor,
                $minorAccount,
                $requestedAccountUuid,
            );
            $this->assertAllowed($this->fundingPolicy->validateLinkCreation(
                $actor,
                $minorAccount,
                $amountMode,
                $fixedAmount,
                $targetAmount,
                $providerOptions,
                $expiresAt,
            ));

            return $this->persistFundingLink(
                actor: $actor,
                minorAccount: $minorAccount,
                attributes: $attributes,
                actingAccount: $actingAccount,
                amountMode: $amountMode,
                fixedAmount: $fixedAmount,
                targetAmount: $targetAmount,
                providerOptions: $providerOptions,
                expiresAt: $expiresAt,
            );
        }

        $payloadHash = $this->hashPayload([
            'minor_account_uuid' => $minorAccount->uuid,
            'created_by_account_uuid' => $requestedAccountUuid,
            'title' => $this->stringValue($attributes, 'title'),
            'note' => $this->nullableStringValue($attributes, 'note'),
            'amount_mode' => $amountMode,
            'fixed_amount' => $fixedAmount,
            'target_amount' => $targetAmount,
            'asset_code' => $this->normaliseAssetCode($attributes['asset_code'] ?? 'SZL'),
            'provider_options' => $providerOptions,
            'expires_at' => $expiresAt?->toIso8601String(),
            'tenant_id' => $this->resolveTenantId($attributes, $actor, $minorAccount),
        ]);

        try {
            $result = $this->operationRecords->guardAndRun(
                $actor->id,
                'minor_family_funding_link',
                $idempotencyKey,
                $payloadHash,
                function () use (
                    $actor,
                    $minorAccount,
                    $attributes,
                    $requestedAccountUuid,
                    $amountMode,
                    $fixedAmount,
                    $targetAmount,
                    $providerOptions,
                    $expiresAt
                ): array {
                    $actingAccount = $this->accessService->authorizeGuardian(
                        $actor,
                        $minorAccount,
                        $requestedAccountUuid,
                    );
                    $this->assertAllowed($this->fundingPolicy->validateLinkCreation(
                        $actor,
                        $minorAccount,
                        $amountMode,
                        $fixedAmount,
                        $targetAmount,
                        $providerOptions,
                        $expiresAt,
                    ));

                    return ['funding_link_uuid' => $this->persistFundingLink(
                        actor: $actor,
                        minorAccount: $minorAccount,
                        attributes: $attributes,
                        actingAccount: $actingAccount,
                        amountMode: $amountMode,
                        fixedAmount: $fixedAmount,
                        targetAmount: $targetAmount,
                        providerOptions: $providerOptions,
                        expiresAt: $expiresAt,
                    )->id];
                },
            );
        } catch (Throwable $exception) {
            $this->deleteFailedOperationRecord(
                userId: $actor->id,
                type: 'minor_family_funding_link',
                idempotencyKey: $idempotencyKey,
                payloadHash: $payloadHash,
            );

            throw $exception;
        }

        /** @var MinorFamilyFundingLink $link */
        $link = MinorFamilyFundingLink::query()->findOrFail($result['funding_link_uuid']);

        return $link;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>|null  $providerOptions
     */
    private function persistFundingLink(
        User $actor,
        Account $minorAccount,
        array $attributes,
        Account $actingAccount,
        string $amountMode,
        ?string $fixedAmount,
        ?string $targetAmount,
        ?array $providerOptions,
        ?\Carbon\CarbonInterface $expiresAt,
    ): MinorFamilyFundingLink {
        /** @var MinorFamilyFundingLink $link */
        $link = DB::transaction(function () use (
            $attributes,
            $minorAccount,
            $actor,
            $actingAccount,
            $amountMode,
            $fixedAmount,
            $targetAmount,
            $providerOptions,
            $expiresAt,
        ): MinorFamilyFundingLink {
            $link = MinorFamilyFundingLink::query()->create([
                'tenant_id' => $this->resolveTenantId($attributes, $actor, $minorAccount),
                'minor_account_uuid' => $minorAccount->uuid,
                'created_by_user_uuid' => $actor->uuid,
                'created_by_account_uuid' => $actingAccount->uuid,
                'title' => $this->stringValue($attributes, 'title'),
                'note' => $this->nullableStringValue($attributes, 'note'),
                'token' => $this->nullableStringValue($attributes, 'token') ?? (string) \Illuminate\Support\Str::uuid(),
                'status' => MinorFamilyFundingLink::STATUS_ACTIVE,
                'amount_mode' => $amountMode,
                'fixed_amount' => $amountMode === MinorFamilyFundingLink::AMOUNT_MODE_FIXED ? $this->normaliseAmount($fixedAmount) : null,
                'target_amount' => $amountMode === MinorFamilyFundingLink::AMOUNT_MODE_CAPPED ? $this->normaliseAmount($targetAmount) : null,
                'collected_amount' => '0.00',
                'asset_code' => $this->normaliseAssetCode($attributes['asset_code'] ?? 'SZL'),
                'provider_options' => $providerOptions,
                'expires_at' => $expiresAt,
            ]);

            $this->notifications->notify(
                minorAccountUuid: $minorAccount->uuid,
                type: MinorNotificationService::TYPE_FAMILY_FUNDING_LINK_CREATED,
                data: [
                    'funding_link_uuid' => $link->id,
                    'title' => $link->title,
                    'amount_mode' => $link->amount_mode,
                    'asset_code' => $link->asset_code,
                    'status' => $link->status,
                ],
                actorUserUuid: $actor->uuid,
                targetType: 'minor_family_funding_link',
                targetId: $link->id,
            );

            event(new MinorFamilyFundingLinkCreated(
                $link->id,
                $minorAccount->uuid,
                $actor->uuid,
            ));

            return $link;
        });

        return $link;
    }

    public function expireFundingLink(
        User $actor,
        Account $minorAccount,
        MinorFamilyFundingLink $fundingLink,
    ): MinorFamilyFundingLink {
        $this->accessService->authorizeGuardian($actor, $minorAccount);

        if ($fundingLink->minor_account_uuid !== $minorAccount->uuid) {
            throw ValidationException::withMessages([
                'funding_link_uuid' => ['Funding link does not belong to the selected minor account.'],
            ]);
        }

        if ($fundingLink->isTerminal()) {
            return $fundingLink;
        }

        DB::transaction(function () use ($fundingLink, $minorAccount, $actor): void {
            $fundingLink->forceFill([
                'status' => MinorFamilyFundingLink::STATUS_EXPIRED,
                'expires_at' => now(),
            ])->save();

            $this->notifications->notify(
                minorAccountUuid: $minorAccount->uuid,
                type: MinorNotificationService::TYPE_FAMILY_FUNDING_LINK_EXPIRED,
                data: [
                    'funding_link_uuid' => $fundingLink->id,
                    'status' => $fundingLink->status,
                ],
                actorUserUuid: $actor->uuid,
                targetType: 'minor_family_funding_link',
                targetId: $fundingLink->id,
            );

            event(new MinorFamilyFundingLinkExpired(
                $fundingLink->id,
                $minorAccount->uuid,
                $actor->uuid,
            ));
        });

        return $fundingLink->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createOutboundSupportTransfer(
        User $actor,
        Account $minorAccount,
        array $attributes,
    ): MinorFamilySupportTransfer {
        $idempotencyKey = $this->stringValue($attributes, 'idempotency_key');
        $requestedSourceAccountUuid = $this->nullableStringValue($attributes, 'source_account_uuid');
        $provider = $this->normaliseProvider($attributes['provider'] ?? null);
        $amount = $this->normaliseAmount($attributes['amount'] ?? null);
        $assetCode = $this->normaliseAssetCode($attributes['asset_code'] ?? null);
        $payloadHash = $this->hashPayload([
            'minor_account_uuid' => $minorAccount->uuid,
            'source_account_uuid' => $requestedSourceAccountUuid,
            'provider' => $provider,
            'recipient_name' => $this->stringValue($attributes, 'recipient_name'),
            'recipient_msisdn' => $this->normaliseMsisdn($this->stringValue($attributes, 'recipient_msisdn')),
            'amount' => $amount,
            'asset_code' => $assetCode,
            'note' => $this->nullableStringValue($attributes, 'note'),
            'tenant_id' => $this->resolveTenantId($attributes, $actor, $minorAccount),
        ]);

        try {
            $result = $this->operationRecords->guardAndRun(
                $actor->id,
                'minor_family_support_transfer',
                $idempotencyKey,
                $payloadHash,
                function () use (
                    $actor,
                    $minorAccount,
                    $requestedSourceAccountUuid,
                    $provider,
                    $amount,
                    $attributes,
                    $idempotencyKey,
                    $assetCode,
                ): array {
                    $sourceAccount = $this->accessService->authorizeGuardian(
                        $actor,
                        $minorAccount,
                        $requestedSourceAccountUuid,
                    );

                    if ($requestedSourceAccountUuid !== null && $sourceAccount->uuid !== $requestedSourceAccountUuid) {
                        throw new AuthorizationException('Forbidden. Guardian access requires a valid owned account context.');
                    }

                    $this->assertAllowed($this->fundingPolicy->validateOutboundSupportTransfer(
                        $actor,
                        $minorAccount,
                        $sourceAccount,
                        $provider,
                        $amount,
                    ));

                    return $this->runOutboundSupportTransfer(
                        actor: $actor,
                        minorAccount: $minorAccount,
                        sourceAccount: $sourceAccount,
                        attributes: $attributes,
                        idempotencyKey: $idempotencyKey,
                        provider: $provider,
                        amount: $amount,
                        assetCode: $assetCode,
                    );
                },
            );
        } catch (Throwable $exception) {
            $this->deleteFailedOperationRecord(
                userId: $actor->id,
                type: 'minor_family_support_transfer',
                idempotencyKey: $idempotencyKey,
                payloadHash: $payloadHash,
            );
            $this->deleteFailedTransientSupportTransfer(
                actorUserUuid: $actor->uuid,
                idempotencyKey: $idempotencyKey,
            );

            throw $exception;
        }

        /** @var MinorFamilySupportTransfer $transfer */
        $transfer = MinorFamilySupportTransfer::query()
            ->findOrFail($result['family_support_transfer_uuid']);

        return $transfer;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{family_support_transfer_uuid: string}
     */
    private function runOutboundSupportTransfer(
        User $actor,
        Account $minorAccount,
        Account $sourceAccount,
        array $attributes,
        string $idempotencyKey,
        string $provider,
        string $amount,
        string $assetCode,
    ): array {
        $tenantId = $this->resolveTenantId($attributes, $actor, $minorAccount);

        ['transfer' => $transfer, 'providerTransaction' => $providerTransaction] = DB::transaction(function () use (
            $tenantId,
            $minorAccount,
            $actor,
            $sourceAccount,
            $provider,
            $attributes,
            $amount,
            $assetCode,
            $idempotencyKey,
        ): array {
            $transfer = MinorFamilySupportTransfer::query()->create([
                'tenant_id' => $tenantId,
                'minor_account_uuid' => $minorAccount->uuid,
                'actor_user_uuid' => $actor->uuid,
                'source_account_uuid' => $sourceAccount->uuid,
                'status' => MinorFamilySupportTransfer::STATUS_PENDING_PROVIDER,
                'provider_name' => $provider,
                'recipient_name' => $this->stringValue($attributes, 'recipient_name'),
                'recipient_msisdn' => $this->stringValue($attributes, 'recipient_msisdn'),
                'amount' => $amount,
                'asset_code' => $assetCode,
                'note' => $this->nullableStringValue($attributes, 'note'),
                'idempotency_key' => $idempotencyKey,
            ]);

            $providerTransaction = MtnMomoTransaction::query()->create([
                'id' => (string) $transfer->id,
                'user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'type' => MtnMomoTransaction::TYPE_DISBURSEMENT,
                'amount' => $amount,
                'currency' => $assetCode,
                'status' => MtnMomoTransaction::STATUS_PENDING,
                'party_msisdn' => $this->stringValue($attributes, 'recipient_msisdn'),
                'note' => $this->nullableStringValue($attributes, 'note'),
            ]);

            $providerTransaction->forceFill([
                'context_type' => MinorFamilySupportTransfer::class,
                'context_uuid' => $transfer->id,
            ])->save();

            $transfer->forceFill([
                'mtn_momo_transaction_id' => $providerTransaction->id,
            ])->save();

            return [
                'transfer' => $transfer,
                'providerTransaction' => $providerTransaction,
            ];
        });

        try {
            $providerResponse = $this->fundingAdapter->initiateOutboundDisbursement([
                'idempotency_key' => $idempotencyKey,
                'source_account_uuid' => $sourceAccount->uuid,
                'minor_account_uuid' => $minorAccount->uuid,
                'recipient_name' => $transfer->recipient_name,
                'recipient_msisdn' => $transfer->recipient_msisdn,
                'amount' => $amount,
                'asset_code' => $assetCode,
                'note' => $transfer->note,
            ]);
        } catch (Throwable $exception) {
            $this->markSupportTransferProviderInitiationFailed($transfer->id, $providerTransaction->id);
            throw $exception;
        }

        $providerReferenceId = $this->stringValue($providerResponse, 'provider_reference_id');

        DB::transaction(function () use (
            $transfer,
            $providerTransaction,
            $providerReferenceId,
            $providerResponse,
            $minorAccount,
            $actor,
            $sourceAccount,
            $provider,
            $amount,
            $assetCode,
        ): void {
            $providerTransaction->forceFill([
                'mtn_reference_id' => $providerReferenceId,
                'status' => $this->normaliseProviderStatus($providerResponse['provider_status'] ?? null),
            ])->save();

            $transfer->forceFill([
                'provider_reference_id' => $providerReferenceId,
            ])->save();

            $this->notifications->notify(
                minorAccountUuid: $minorAccount->uuid,
                type: MinorNotificationService::TYPE_FAMILY_SUPPORT_TRANSFER_INITIATED,
                data: [
                    'family_support_transfer_uuid' => $transfer->id,
                    'provider_name' => $provider,
                    'provider_reference_id' => $providerReferenceId,
                    'amount' => $amount,
                    'asset_code' => $assetCode,
                    'mtn_momo_transaction_id' => $providerTransaction->id,
                ],
                actorUserUuid: $actor->uuid,
                targetType: 'minor_family_support_transfer',
                targetId: $transfer->id,
            );

            event(new MinorFamilySupportTransferInitiated(
                $transfer->id,
                $minorAccount->uuid,
                $actor->uuid,
                $sourceAccount->uuid,
                $provider,
                $amount,
                $assetCode,
            ));
        });

        return ['family_support_transfer_uuid' => $transfer->id];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createPublicFundingAttempt(
        MinorFamilyFundingLink $link,
        array $attributes,
    ): MinorFamilyFundingAttempt {
        $provider = $this->normaliseProvider($attributes['provider'] ?? null);
        $amount = $this->normaliseAmount($attributes['amount'] ?? null);
        $assetCode = $this->normaliseAssetCode($attributes['asset_code'] ?? $link->asset_code);

        if ($assetCode !== $this->normaliseAssetCode($link->asset_code)) {
            throw ValidationException::withMessages([
                'asset_code' => ['Requested asset code must match the funding link asset code.'],
            ]);
        }

        $dedupeHash = $this->makeFundingAttemptDedupeHash(
            $link,
            $this->stringValue($attributes, 'sponsor_msisdn'),
            $amount,
            $provider,
        );

        $existing = MinorFamilyFundingAttempt::query()
            ->where('dedupe_hash', $dedupeHash)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->assertPublicFundingAttemptAbuseControls(
            $link,
            $this->stringValue($attributes, 'sponsor_msisdn'),
        );

        $this->assertAllowed($this->fundingPolicy->validateFundingAttempt(
            $link,
            $amount,
            $provider,
        ));

        $owner = $this->resolveLinkOwner($link);

        try {
            try {
                /** @var MinorFamilyFundingAttempt $attempt */
                $attempt = (function () use (
                    $link,
                    $attributes,
                    $provider,
                    $amount,
                    $assetCode,
                    $dedupeHash,
                    $owner,
                ): MinorFamilyFundingAttempt {
                ['attempt' => $attempt, 'providerTransaction' => $providerTransaction] = DB::transaction(function () use (
                    $link,
                    $attributes,
                    $provider,
                    $amount,
                    $assetCode,
                    $dedupeHash,
                    $owner,
                ): array {
                    $attempt = MinorFamilyFundingAttempt::query()->create([
                        'tenant_id' => $link->tenant_id,
                        'funding_link_uuid' => $link->id,
                        'minor_account_uuid' => $link->minor_account_uuid,
                        'status' => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
                        'sponsor_name' => $this->stringValue($attributes, 'sponsor_name'),
                        'sponsor_msisdn' => $this->stringValue($attributes, 'sponsor_msisdn'),
                        'amount' => $amount,
                        'asset_code' => $assetCode,
                        'provider_name' => $provider,
                        'dedupe_hash' => $dedupeHash,
                    ]);

                    $providerTransaction = MtnMomoTransaction::query()->create([
                        'id' => (string) $attempt->id,
                        'user_id' => $owner->id,
                        'idempotency_key' => $dedupeHash,
                        'type' => MtnMomoTransaction::TYPE_REQUEST_TO_PAY,
                        'amount' => $amount,
                        'currency' => $assetCode,
                        'status' => MtnMomoTransaction::STATUS_PENDING,
                        'party_msisdn' => $attempt->sponsor_msisdn,
                        'note' => $link->note,
                    ]);

                    $providerTransaction->forceFill([
                        'context_type' => MinorFamilyFundingAttempt::class,
                        'context_uuid' => $attempt->id,
                    ])->save();

                    $attempt->forceFill([
                        'mtn_momo_transaction_id' => $providerTransaction->id,
                    ])->save();

                    return [
                        'attempt' => $attempt,
                        'providerTransaction' => $providerTransaction,
                    ];
                });

                try {
                    $providerResponse = $this->fundingAdapter->initiateInboundCollection([
                    'idempotency_key' => $dedupeHash,
                    'funding_link_uuid' => $link->id,
                    'minor_account_uuid' => $link->minor_account_uuid,
                    'payer_msisdn' => $attempt->sponsor_msisdn,
                    'amount' => $amount,
                    'asset_code' => $assetCode,
                    'note' => $link->note,
                    ]);
                } catch (Throwable $exception) {
                    $this->markFundingAttemptProviderInitiationFailed($attempt->id, $providerTransaction->id);
                    throw $exception;
                }

                $providerReferenceId = $this->stringValue($providerResponse, 'provider_reference_id');

                DB::transaction(function () use (
                    $attempt,
                    $providerTransaction,
                    $providerReferenceId,
                    $providerResponse,
                    $link,
                    $provider,
                    $amount,
                    $assetCode,
                ): void {
                    $providerTransaction->forceFill([
                        'mtn_reference_id' => $providerReferenceId,
                        'status' => $this->normaliseProviderStatus($providerResponse['provider_status'] ?? null),
                    ])->save();

                    $attempt->forceFill([
                        'provider_reference_id' => $providerReferenceId,
                    ])->save();

                    $this->notifications->notify(
                        minorAccountUuid: $link->minor_account_uuid,
                        type: MinorNotificationService::TYPE_FAMILY_FUNDING_ATTEMPT_INITIATED,
                        data: [
                            'funding_attempt_uuid' => $attempt->id,
                            'funding_link_uuid' => $link->id,
                            'provider_name' => $provider,
                            'provider_reference_id' => $providerReferenceId,
                            'amount' => $amount,
                            'asset_code' => $assetCode,
                            'mtn_momo_transaction_id' => $providerTransaction->id,
                        ],
                        actorUserUuid: $link->created_by_user_uuid,
                        targetType: 'minor_family_funding_attempt',
                        targetId: $attempt->id,
                    );

                    event(new MinorFamilyFundingAttemptInitiated(
                        $attempt->id,
                        $link->id,
                        $link->minor_account_uuid,
                        $provider,
                        $amount,
                        $assetCode,
                    ));
                });

                return $attempt;
                })();
            } catch (UniqueConstraintViolationException $exception) {
                $attempt = MinorFamilyFundingAttempt::query()
                    ->where('dedupe_hash', $dedupeHash)
                    ->first();

                if ($attempt === null) {
                    throw $exception;
                }
            }
        } catch (Throwable $exception) {
            $this->deleteFailedTransientFundingAttempt($dedupeHash);
            throw $exception;
        }

        return $attempt;
    }

    private function assertPublicFundingAttemptAbuseControls(
        MinorFamilyFundingLink $link,
        string $sponsorMsisdn,
    ): void {
        $windowMinutes = max(1, (int) config('minor_family.public_funding.attempt_window_minutes', 10));
        $linkLimit = max(1, (int) config('minor_family.public_funding.link_max_attempts_per_window', 25));
        $sponsorLimit = max(1, (int) config('minor_family.public_funding.sponsor_max_attempts_per_window', 5));

        $recentAttempts = MinorFamilyFundingAttempt::query()
            ->where('funding_link_uuid', $link->id)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->get(['sponsor_msisdn']);

        if ($recentAttempts->count() >= $linkLimit) {
            throw ValidationException::withMessages([
                'minor_family_integration' => ['Too many funding attempts for this support link. Please try again shortly.'],
            ]);
        }

        $normalisedSponsorMsisdn = $this->normaliseMsisdn($sponsorMsisdn);

        $sponsorAttempts = $recentAttempts
            ->filter(fn (MinorFamilyFundingAttempt $attempt): bool => $this->normaliseMsisdn((string) $attempt->sponsor_msisdn) === $normalisedSponsorMsisdn)
            ->count();

        if ($sponsorAttempts >= $sponsorLimit) {
            throw ValidationException::withMessages([
                'minor_family_integration' => ['Too many funding attempts from this sponsor. Please try again shortly.'],
            ]);
        }
    }

    private function assertAllowed(MinorFamilyFundingPolicyResult $result): void
    {
        if ($result->allowed) {
            return;
        }

        throw ValidationException::withMessages([
            'minor_family_integration' => [$result->reason ?? 'Minor family funding policy rejected the request.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveTenantId(array $attributes, ?User $actor = null, ?Account $minorAccount = null): string
    {
        $explicitTenantId = $this->nullableStringValue($attributes, 'tenant_id');
        if ($explicitTenantId !== null) {
            return $explicitTenantId;
        }

        if (function_exists('tenant') && tenant() !== null) {
            return (string) tenant()->getTenantKey();
        }

        if ($actor !== null && $minorAccount !== null) {
            $membershipTenantId = AccountMembership::query()
                ->forAccount($minorAccount->uuid)
                ->forUser($actor->uuid)
                ->active()
                ->value('tenant_id');

            if (is_string($membershipTenantId) && $membershipTenantId !== '') {
                return $membershipTenantId;
            }
        }

        throw new RuntimeException('Unable to resolve tenant_id for Phase 9A integration.');
    }

    private function resolveLinkOwner(MinorFamilyFundingLink $link): User
    {
        /** @var User|null $owner */
        $owner = User::query()
            ->where('uuid', $link->created_by_user_uuid)
            ->first();

        if ($owner === null) {
            throw new RuntimeException('Unable to resolve the funding link owner for MTN transaction persistence.');
        }

        return $owner;
    }

    private function makeFundingAttemptDedupeHash(
        MinorFamilyFundingLink $link,
        string $sponsorMsisdn,
        string $amount,
        string $provider,
    ): string {
        return hash('sha256', implode('|', [
            $link->id,
            $this->normaliseMsisdn($sponsorMsisdn),
            $amount,
            $provider,
            now()->format('YmdHi'),
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload));
    }

    private function normaliseProvider(mixed $provider): string
    {
        return trim((string) $provider);
    }

    /**
     * @param  mixed  $providerOptions
     * @return array<int, string>|null
     */
    private function normaliseProviderOptions(mixed $providerOptions): ?array
    {
        if (! is_array($providerOptions)) {
            return null;
        }

        $providers = array_values(array_filter(array_map(
            static fn (mixed $provider): string => trim((string) $provider),
            $providerOptions,
        ), static fn (string $provider): bool => $provider !== ''));

        return $providers === [] ? null : $providers;
    }

    private function normaliseAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function normaliseAssetCode(mixed $assetCode): string
    {
        return strtoupper(trim((string) $assetCode));
    }

    private function normaliseMsisdn(string $msisdn): string
    {
        return preg_replace('/\D+/', '', $msisdn) ?? '';
    }

    private function normaliseProviderStatus(mixed $status): string
    {
        return MtnMomoTransaction::normaliseRemoteStatus(is_string($status) ? $status : null);
    }

    private function deleteFailedOperationRecord(
        int $userId,
        string $type,
        string $idempotencyKey,
        string $payloadHash,
    ): void {
        OperationRecord::query()
            ->where('user_id', $userId)
            ->where('operation_type', $type)
            ->where('idempotency_key', $idempotencyKey)
            ->where('payload_hash', $payloadHash)
            ->where('status', OperationRecord::STATUS_FAILED)
            ->delete();
    }

    private function deleteFailedTransientSupportTransfer(
        string $actorUserUuid,
        string $idempotencyKey,
    ): void {
        $transfer = MinorFamilySupportTransfer::query()
            ->where('actor_user_uuid', $actorUserUuid)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED)
            ->where('failed_reason', 'provider_initiation_failed')
            ->first();

        if ($transfer === null) {
            return;
        }

        DB::transaction(function () use ($transfer): void {
            MtnMomoTransaction::query()
                ->whereKey($transfer->mtn_momo_transaction_id)
                ->where('status', MtnMomoTransaction::STATUS_FAILED)
                ->where(function ($query): void {
                    $query->whereNull('mtn_reference_id')
                        ->orWhere('mtn_reference_id', '');
                })
                ->delete();

            $transfer->delete();
        });
    }

    private function deleteFailedTransientFundingAttempt(string $dedupeHash): void
    {
        $attempt = MinorFamilyFundingAttempt::query()
            ->where('dedupe_hash', $dedupeHash)
            ->where('status', MinorFamilyFundingAttempt::STATUS_FAILED)
            ->where('failed_reason', 'provider_initiation_failed')
            ->first();

        if ($attempt === null) {
            return;
        }

        DB::transaction(function () use ($attempt): void {
            MtnMomoTransaction::query()
                ->whereKey($attempt->mtn_momo_transaction_id)
                ->where('status', MtnMomoTransaction::STATUS_FAILED)
                ->where(function ($query): void {
                    $query->whereNull('mtn_reference_id')
                        ->orWhere('mtn_reference_id', '');
                })
                ->delete();

            $attempt->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function nullableDateTimeValue(array $attributes, string $key): ?\Carbon\CarbonInterface
    {
        $value = $attributes[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return \Carbon\Carbon::parse($value);
    }

    private function markSupportTransferProviderInitiationFailed(string $transferId, string $providerTransactionId): void
    {
        DB::transaction(function () use ($transferId, $providerTransactionId): void {
            MinorFamilySupportTransfer::query()
                ->whereKey($transferId)
                ->update([
                    'status' => MinorFamilySupportTransfer::STATUS_FAILED_UNRECONCILED,
                    'failed_reason' => 'provider_initiation_failed',
                ]);

            MtnMomoTransaction::query()
                ->whereKey($providerTransactionId)
                ->update([
                    'status' => MtnMomoTransaction::STATUS_FAILED,
                    'last_mtn_status' => 'PROVIDER_INITIATION_FAILED',
                ]);
        });
    }

    private function markFundingAttemptProviderInitiationFailed(string $attemptId, string $providerTransactionId): void
    {
        DB::transaction(function () use ($attemptId, $providerTransactionId): void {
            MinorFamilyFundingAttempt::query()
                ->whereKey($attemptId)
                ->update([
                    'status' => MinorFamilyFundingAttempt::STATUS_FAILED,
                    'failed_reason' => 'provider_initiation_failed',
                ]);

            MtnMomoTransaction::query()
                ->whereKey($providerTransactionId)
                ->update([
                    'status' => MtnMomoTransaction::STATUS_FAILED,
                    'last_mtn_status' => 'PROVIDER_INITIATION_FAILED',
                ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function stringValue(array $attributes, string $key): string
    {
        return trim((string) ($attributes[$key] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function nullableStringValue(array $attributes, string $key): ?string
    {
        $value = trim((string) ($attributes[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
