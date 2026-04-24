<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Aggregates\MinorAccountLifecycleAggregate;
use App\Domain\Account\Events\MinorAccountAdultTransitionCompleted;
use App\Domain\Account\Events\MinorAccountAdultTransitionFrozen;
use App\Domain\Account\Events\MinorAccountGuardianContinuityBroken;
use App\Domain\Account\Events\MinorAccountLifecycleExceptionOpened;
use App\Domain\Account\Events\MinorAccountLifecycleExceptionResolved;
use App\Domain\Account\Events\MinorAccountLifecycleTransitionBlocked;
use App\Domain\Account\Events\MinorAccountLifecycleTransitionScheduled;
use App\Domain\Account\Events\MinorAccountTierAdvanced;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorAccountLifecycleException;
use App\Domain\Account\Models\MinorAccountLifecycleExceptionAcknowledgment;
use App\Domain\Account\Models\MinorAccountLifecycleTransition;
use App\Domain\Monitoring\Services\MetricsCollector;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MinorAccountLifecycleService
{
    public function __construct(
        private readonly MinorAccountLifecyclePolicy $policy,
        private readonly AccountService $accountService,
        private readonly MinorNotificationService $notificationService,
        private readonly MetricsCollector $metricsCollector,
    ) {
    }

    /**
     * @return array{scheduled:int, completed:int, blocked:int, exceptions_opened:int}
     */
    public function evaluateAccount(Account $minorAccount, string $source = 'scheduler'): array
    {
        $tenantId = $this->resolveTenantId($minorAccount);
        $scheduled = 0;
        $completed = 0;
        $blocked = 0;
        $exceptionsOpened = 0;

        return DB::transaction(function () use (
            $minorAccount,
            $tenantId,
            $source,
            &$scheduled,
            &$completed,
            &$blocked,
            &$exceptionsOpened,
        ): array {
            /** @var Account $freshAccount */
            $freshAccount = Account::query()
                ->where('uuid', $minorAccount->uuid)
                ->lockForUpdate()
                ->firstOrFail();

        $tierEvaluation = $this->policy->evaluateTierAdvance($freshAccount);
        if ($tierEvaluation['eligible']) {
            $transition = $this->scheduleTransition(
                tenantId: $tenantId,
                minorAccount: $freshAccount,
                transitionType: MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE,
                effectiveAt: now()->startOfDay(),
                metadata: [
                    'source' => $source,
                    'target_tier' => $tierEvaluation['target_tier'],
                    'target_permission_level' => $tierEvaluation['target_permission_level'],
                ],
            );
            $scheduled += $transition->wasRecentlyCreated ? 1 : 0;
        }

        $this->scheduleAdultReviewMilestones($freshAccount, $tenantId, $source, $scheduled);

        $guardianContinuity = $this->policy->evaluateGuardianContinuity($freshAccount);
        if (! $guardianContinuity['valid']) {
            $transition = $this->scheduleTransition(
                tenantId: $tenantId,
                minorAccount: $freshAccount,
                transitionType: MinorAccountLifecycleTransition::TYPE_GUARDIAN_CONTINUITY,
                effectiveAt: now()->startOfDay(),
                metadata: [
                    'source' => $source,
                    'active_guardian_count' => $guardianContinuity['active_guardian_count'],
                ],
            );
            $scheduled += $transition->wasRecentlyCreated ? 1 : 0;
        } else {
            $this->resolveOpenExceptionsForReason(
                minorAccount: $freshAccount,
                reasonCode: MinorAccountLifecyclePolicy::REASON_GUARDIAN_CONTINUITY_BROKEN,
                source: $source,
                metadata: ['source' => $source],
            );
        }

        /** @var Collection<int, MinorAccountLifecycleTransition> $dueTransitions */
        $dueTransitions = MinorAccountLifecycleTransition::query()
            ->where('minor_account_uuid', $freshAccount->uuid)
            ->where('state', MinorAccountLifecycleTransition::STATE_PENDING)
            ->where('effective_at', '<=', now())
            ->orderBy('effective_at')
            ->get();

        foreach ($dueTransitions as $transition) {
            $result = $this->executeTransition($freshAccount, $transition, $source);
            $completed += $result['completed'];
            $blocked += $result['blocked'];
            $exceptionsOpened += $result['exceptions_opened'];
        }

        return [
            'scheduled' => $scheduled,
            'completed' => $completed,
            'blocked' => $blocked,
            'exceptions_opened' => $exceptionsOpened,
        ];
        }); // end DB::transaction
    }

    /**
     * @return array<string, mixed>
     */
    public function lifecycleSnapshot(Account $minorAccount): array
    {
        $ageContext = $this->policy->ageContext($minorAccount);
        $adultTransition = $this->policy->evaluateAdultTransition($minorAccount);
        $guardianContinuity = $this->policy->evaluateGuardianContinuity($minorAccount);

        $transitions = MinorAccountLifecycleTransition::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->orderByDesc('effective_at')
            ->get();

        $exceptions = MinorAccountLifecycleException::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->orderByDesc('last_seen_at')
            ->get();

        $pendingMilestones = $transitions
            ->where('state', MinorAccountLifecycleTransition::STATE_PENDING)
            ->sortBy('effective_at')
            ->map(fn (MinorAccountLifecycleTransition $transition): array => [
                'transition_uuid' => $transition->id,
                'transition_type' => $transition->transition_type,
                'effective_at' => $transition->effective_at?->toIso8601String(),
                'metadata' => $transition->metadata,
            ])
            ->values()
            ->all();

        $blockers = $exceptions
            ->where('status', MinorAccountLifecycleException::STATUS_OPEN)
            ->map(fn (MinorAccountLifecycleException $exception): array => [
                'exception_uuid' => $exception->id,
                'reason_code' => $exception->reason_code,
                'status' => $exception->status,
                'sla_due_at' => $exception->sla_due_at?->toIso8601String(),
                'source' => $exception->source,
            ])
            ->values()
            ->all();

        return [
            'minor_account_uuid' => $minorAccount->uuid,
            'tier' => $minorAccount->tier,
            'permission_level' => $minorAccount->permission_level,
            'frozen' => (bool) $minorAccount->frozen,
            'transition_state' => $minorAccount->minor_transition_state,
            'transition_effective_at' => $minorAccount->minor_transition_effective_at?->toIso8601String(),
            'age' => $ageContext['age'],
            'date_of_birth' => $ageContext['date_of_birth']?->toDateString(),
            'pending_milestones' => $pendingMilestones,
            'blockers' => $blockers,
            'next_actions' => $this->nextActions($adultTransition, $guardianContinuity, $blockers),
        ];
    }

    public function acknowledgeException(MinorAccountLifecycleException $exception, User $actor, string $note): void
    {
        DB::transaction(function () use ($exception, $actor, $note): void {
            /** @var MinorAccountLifecycleException $locked */
            $locked = MinorAccountLifecycleException::query()
                ->whereKey($exception->id)
                ->firstOrFail();

            $ack = MinorAccountLifecycleExceptionAcknowledgment::query()->create([
                'minor_account_lifecycle_exception_id' => $locked->id,
                'acknowledged_by_user_uuid' => $actor->uuid,
                'note' => $note,
            ]);

            /** @var array<string, mixed> $metadata */
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['manual_review'] = [
                'acknowledged_by_user_uuid' => $actor->uuid,
                'acknowledged_at' => now()->toIso8601String(),
                'note' => $note,
                'latest_acknowledgment_id' => $ack->id,
            ];

            $locked->forceFill(['metadata' => $metadata])->save();
        });
    }

    public function resolveException(MinorAccountLifecycleException $exception, User $actor, string $note, string $source = 'api'): MinorAccountLifecycleException
    {
        /** @var MinorAccountLifecycleException $resolved */
        $resolved = DB::transaction(function () use ($exception, $actor, $note, $source): MinorAccountLifecycleException {
            /** @var MinorAccountLifecycleException $locked */
            $locked = MinorAccountLifecycleException::query()
                ->whereKey($exception->id)
                ->firstOrFail();

            $wasOpen = $locked->status === MinorAccountLifecycleException::STATUS_OPEN;

            $this->acknowledgeException($locked, $actor, $note);

            /** @var array<string, mixed> $metadata */
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $metadata['resolution'] = [
                'source' => $source,
                'resolved_by_user_uuid' => $actor->uuid,
                'resolved_at' => now()->toIso8601String(),
                'note' => $note,
            ];

            $locked->forceFill([
                'status' => MinorAccountLifecycleException::STATUS_RESOLVED,
                'source' => $source,
                'metadata' => $metadata,
                'resolved_at' => now(),
            ])->save();

            if ($wasOpen) {
                $this->metricsCollector->recordMinorLifecycleExceptionResolved();
            }

            new MinorAccountLifecycleExceptionResolved(
                $locked->id,
                $locked->minor_account_uuid,
                $locked->reason_code,
                $metadata,
            );

            return $locked;
        });

        return $resolved;
    }

    public function exceptionQueryForAccount(Account $minorAccount): Builder
    {
        return MinorAccountLifecycleException::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->orderByDesc('last_seen_at');
    }

    public function transitionQueryForAccount(Account $minorAccount): Builder
    {
        return MinorAccountLifecycleTransition::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->orderByDesc('effective_at');
    }

    /**
     * @return array{completed:int, blocked:int, exceptions_opened:int}
     */
    private function executeTransition(Account $minorAccount, MinorAccountLifecycleTransition $transition, string $source): array
    {
        return match ($transition->transition_type) {
            MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE => $this->executeTierAdvance($minorAccount, $transition, $source),
            MinorAccountLifecycleTransition::TYPE_ADULT_TRANSITION_REVIEW,
            MinorAccountLifecycleTransition::TYPE_ADULT_TRANSITION_CUTOFF => $this->executeAdultTransition($minorAccount, $transition, $source),
            MinorAccountLifecycleTransition::TYPE_GUARDIAN_CONTINUITY => $this->executeGuardianContinuity($minorAccount, $transition, $source),
            default => ['completed' => 0, 'blocked' => 0, 'exceptions_opened' => 0],
        };
    }

    /**
     * @return array{completed:int, blocked:int, exceptions_opened:int}
     */
    private function executeTierAdvance(Account $minorAccount, MinorAccountLifecycleTransition $transition, string $source): array
    {
        $evaluation = $this->policy->evaluateTierAdvance($minorAccount);

        if (! $evaluation['eligible']) {
            $aggregate = MinorAccountLifecycleAggregate::initialize($minorAccount->uuid);
            $aggregate->blockTierAdvance((string) $evaluation['reason_code']);
            $aggregate->persist();

            $transition->forceFill([
                'state' => MinorAccountLifecycleTransition::STATE_BLOCKED,
                'blocked_reason_code' => $evaluation['reason_code'],
                'executed_at' => now(),
            ])->save();

            $this->recordLifecycleException(
                minorAccount: $minorAccount,
                transition: $transition,
                source: $source,
                reasonCode: (string) $evaluation['reason_code'],
                metadata: ['transition_type' => $transition->transition_type],
            );

            $this->metricsCollector->recordMinorLifecycleTransitionBlocked();
            new MinorAccountLifecycleTransitionBlocked($transition->id, $minorAccount->uuid, (string) $evaluation['reason_code']);

            return ['completed' => 0, 'blocked' => 1, 'exceptions_opened' => 1];
        }

        $fromTier = (string) $minorAccount->tier;

        $aggregate = MinorAccountLifecycleAggregate::initialize($minorAccount->uuid);
        $aggregate->transitionToTier(
            targetTier: (string) $evaluation['target_tier'],
            targetPermissionLevel: (string) $evaluation['target_permission_level'],
            effectiveAt: $transition->effective_at !== null ? $transition->effective_at->toIso8601String() : null,
        );
        $aggregate->persist();

        $transition->forceFill([
            'state' => MinorAccountLifecycleTransition::STATE_COMPLETED,
            'executed_at' => now(),
            'blocked_reason_code' => null,
        ])->save();

        $this->resolveOpenExceptionsForReason(
            minorAccount: $minorAccount,
            reasonCode: MinorAccountLifecyclePolicy::REASON_MISSING_DATE_OF_BIRTH,
            source: $source,
            metadata: ['transition_type' => $transition->transition_type],
        );

        $this->notificationService->notify(
            $minorAccount->uuid,
            MinorNotificationService::TYPE_LIFECYCLE_TIER_ADVANCED,
            [
                'transition_id' => $transition->id,
                'from_tier' => $fromTier,
                'to_tier' => $evaluation['target_tier'],
            ],
            targetType: MinorAccountLifecycleTransition::class,
            targetId: $transition->id,
        );

        new MinorAccountTierAdvanced($minorAccount->uuid, $fromTier, (string) $evaluation['target_tier']);

        return ['completed' => 1, 'blocked' => 0, 'exceptions_opened' => 0];
    }

    /**
     * @return array{completed:int, blocked:int, exceptions_opened:int}
     */
    private function executeAdultTransition(Account $minorAccount, MinorAccountLifecycleTransition $transition, string $source): array
    {
        $adultTransition = $this->policy->evaluateAdultTransition($minorAccount);

        if (! $adultTransition['ready']) {
            $aggregate = MinorAccountLifecycleAggregate::initialize($minorAccount->uuid);
            $aggregate->freezeForAdultTransition(
                reasonCode: (string) $adultTransition['reason_code'],
                effectiveAt: $transition->effective_at !== null ? $transition->effective_at->toIso8601String() : null,
            );
            $aggregate->persist();

            $transition->forceFill([
                'state' => MinorAccountLifecycleTransition::STATE_BLOCKED,
                'blocked_reason_code' => $adultTransition['reason_code'],
                'executed_at' => now(),
            ])->save();

            $this->recordLifecycleException(
                minorAccount: $minorAccount,
                transition: $transition,
                source: $source,
                reasonCode: (string) $adultTransition['reason_code'],
                metadata: ['transition_type' => $transition->transition_type],
            );

            $this->notificationService->notify(
                $minorAccount->uuid,
                MinorNotificationService::TYPE_LIFECYCLE_ADULT_TRANSITION_FROZEN,
                [
                    'transition_id' => $transition->id,
                    'reason_code' => $adultTransition['reason_code'],
                ],
                targetType: MinorAccountLifecycleTransition::class,
                targetId: $transition->id,
            );

            new MinorAccountAdultTransitionFrozen($minorAccount->uuid, (string) $adultTransition['reason_code']);
            $this->metricsCollector->recordMinorLifecycleTransitionBlocked();
            new MinorAccountLifecycleTransitionBlocked($transition->id, $minorAccount->uuid, (string) $adultTransition['reason_code']);

            return ['completed' => 0, 'blocked' => 1, 'exceptions_opened' => 1];
        }

        $aggregate = MinorAccountLifecycleAggregate::initialize($minorAccount->uuid);
        $aggregate->transitionToAdult(
            effectiveAt: $transition->effective_at !== null ? $transition->effective_at->toIso8601String() : now()->toIso8601String(),
        );
        $aggregate->persist();

        $transition->forceFill([
            'state' => MinorAccountLifecycleTransition::STATE_COMPLETED,
            'executed_at' => now(),
            'blocked_reason_code' => null,
        ])->save();

        $this->resolveOpenExceptionsForReason(
            minorAccount: $minorAccount,
            reasonCode: MinorAccountLifecyclePolicy::REASON_ADULT_KYC_NOT_READY,
            source: $source,
            metadata: ['transition_type' => $transition->transition_type],
        );

        $this->notificationService->notify(
            $minorAccount->uuid,
            MinorNotificationService::TYPE_LIFECYCLE_ADULT_TRANSITION_COMPLETED,
            ['transition_id' => $transition->id],
            targetType: MinorAccountLifecycleTransition::class,
            targetId: $transition->id,
        );

        new MinorAccountAdultTransitionCompleted($minorAccount->uuid);

        return ['completed' => 1, 'blocked' => 0, 'exceptions_opened' => 0];
    }

    /**
     * @return array{completed:int, blocked:int, exceptions_opened:int}
     */
    private function executeGuardianContinuity(Account $minorAccount, MinorAccountLifecycleTransition $transition, string $source): array
    {
        $guardianContinuity = $this->policy->evaluateGuardianContinuity($minorAccount);

        if (! $guardianContinuity['valid']) {
            $aggregate = MinorAccountLifecycleAggregate::initialize($minorAccount->uuid);
            $aggregate->breakGuardianContinuity(
                reasonCode: (string) $guardianContinuity['reason_code'],
                effectiveAt: $transition->effective_at !== null ? $transition->effective_at->toIso8601String() : null,
            );
            $aggregate->persist();

            $transition->forceFill([
                'state' => MinorAccountLifecycleTransition::STATE_BLOCKED,
                'blocked_reason_code' => $guardianContinuity['reason_code'],
                'executed_at' => now(),
            ])->save();

            $this->recordLifecycleException(
                minorAccount: $minorAccount,
                transition: $transition,
                source: $source,
                reasonCode: (string) $guardianContinuity['reason_code'],
                metadata: ['transition_type' => $transition->transition_type],
            );

            $this->notificationService->notify(
                $minorAccount->uuid,
                MinorNotificationService::TYPE_LIFECYCLE_GUARDIAN_CONTINUITY_BROKEN,
                [
                    'transition_id' => $transition->id,
                    'reason_code' => $guardianContinuity['reason_code'],
                ],
                targetType: MinorAccountLifecycleTransition::class,
                targetId: $transition->id,
            );

            new MinorAccountGuardianContinuityBroken($minorAccount->uuid, (string) $guardianContinuity['reason_code']);
            $this->metricsCollector->recordMinorLifecycleTransitionBlocked();
            new MinorAccountLifecycleTransitionBlocked($transition->id, $minorAccount->uuid, (string) $guardianContinuity['reason_code']);

            return ['completed' => 0, 'blocked' => 1, 'exceptions_opened' => 1];
        }

        $aggregate = MinorAccountLifecycleAggregate::initialize($minorAccount->uuid);
        $aggregate->restoreGuardianContinuity();
        $aggregate->persist();

        $transition->forceFill([
            'state' => MinorAccountLifecycleTransition::STATE_COMPLETED,
            'executed_at' => now(),
            'blocked_reason_code' => null,
        ])->save();

        $this->resolveOpenExceptionsForReason(
            minorAccount: $minorAccount,
            reasonCode: MinorAccountLifecyclePolicy::REASON_GUARDIAN_CONTINUITY_BROKEN,
            source: $source,
            metadata: ['transition_type' => $transition->transition_type],
        );

        return ['completed' => 1, 'blocked' => 0, 'exceptions_opened' => 0];
    }

    private function scheduleAdultReviewMilestones(Account $minorAccount, string $tenantId, string $source, int &$scheduled): void
    {
        $turning18 = $this->policy->turning18Date($minorAccount);
        if ($turning18 === null) {
            return;
        }

        foreach ([90, 60, 30] as $daysBefore) {
            $effectiveAt = $turning18->copy()->subDays($daysBefore)->startOfDay();
            if ($effectiveAt->greaterThan(now()->addDay())) {
                continue;
            }

            $transition = $this->scheduleTransition(
                tenantId: $tenantId,
                minorAccount: $minorAccount,
                transitionType: MinorAccountLifecycleTransition::TYPE_ADULT_TRANSITION_REVIEW,
                effectiveAt: $effectiveAt,
                metadata: [
                    'milestone' => 't_minus_' . $daysBefore,
                    'source' => $source,
                ],
            );
            $scheduled += $transition->wasRecentlyCreated ? 1 : 0;
        }

        $cutoffTransition = $this->scheduleTransition(
            tenantId: $tenantId,
            minorAccount: $minorAccount,
            transitionType: MinorAccountLifecycleTransition::TYPE_ADULT_TRANSITION_CUTOFF,
            effectiveAt: $turning18->copy()->startOfDay(),
            metadata: [
                'milestone' => 't0',
                'source' => $source,
            ],
        );
        $scheduled += $cutoffTransition->wasRecentlyCreated ? 1 : 0;
    }

    private function scheduleTransition(
        string $tenantId,
        Account $minorAccount,
        string $transitionType,
        Carbon $effectiveAt,
        array $metadata = [],
    ): MinorAccountLifecycleTransition {
        /** @var MinorAccountLifecycleTransition $transition */
        $transition = MinorAccountLifecycleTransition::query()->firstOrCreate(
            [
                'minor_account_uuid' => $minorAccount->uuid,
                'transition_type' => $transitionType,
                'effective_at' => $effectiveAt,
            ],
            [
                'tenant_id' => $tenantId,
                'state' => MinorAccountLifecycleTransition::STATE_PENDING,
                'metadata' => $metadata,
            ],
        );

        if ($transition->wasRecentlyCreated) {
            $this->metricsCollector->recordMinorLifecycleTransitionScheduled();
            new MinorAccountLifecycleTransitionScheduled(
                $transition->id,
                $minorAccount->uuid,
                $transition->transition_type,
                $effectiveAt->toIso8601String(),
                $metadata,
            );
        }

        return $transition;
    }

    private function recordLifecycleException(
        Account $minorAccount,
        MinorAccountLifecycleTransition $transition,
        string $source,
        string $reasonCode,
        array $metadata = [],
    ): MinorAccountLifecycleException {
        /** @var MinorAccountLifecycleException $exception */
        $exception = MinorAccountLifecycleException::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->where('reason_code', $reasonCode)
            ->where('status', MinorAccountLifecycleException::STATUS_OPEN)
            ->first() ?? new MinorAccountLifecycleException();

        $isNew = ! $exception->exists;
        $firstSeenAt = $isNew ? now() : $exception->first_seen_at;
        $occurrenceCount = $isNew ? 1 : $exception->occurrence_count + 1;

        $exception->forceFill([
            'tenant_id' => $this->resolveTenantId($minorAccount),
            'minor_account_uuid' => $minorAccount->uuid,
            'transition_id' => $transition->id,
            'reason_code' => $reasonCode,
            'status' => MinorAccountLifecycleException::STATUS_OPEN,
            'source' => $source,
            'occurrence_count' => $occurrenceCount,
            'metadata' => $metadata,
            'first_seen_at' => $firstSeenAt,
            'last_seen_at' => now(),
            'sla_due_at' => $exception->sla_due_at ?? now()->addHours(24),
            'resolved_at' => null,
        ])->save();

        if ($isNew) {
            $this->metricsCollector->recordMinorLifecycleExceptionOpened();
        }

        new MinorAccountLifecycleExceptionOpened(
            $exception->id,
            $minorAccount->uuid,
            $reasonCode,
            $metadata,
        );

        return $exception;
    }

    private function resolveOpenExceptionsForReason(Account $minorAccount, string $reasonCode, string $source, array $metadata = []): void
    {
        $openExceptions = MinorAccountLifecycleException::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->where('reason_code', $reasonCode)
            ->where('status', MinorAccountLifecycleException::STATUS_OPEN)
            ->get();

        foreach ($openExceptions as $exception) {
            /** @var array<string, mixed> $existingMetadata */
            $existingMetadata = is_array($exception->metadata) ? $exception->metadata : [];
            $existingMetadata['resolution'] = array_merge([
                'source' => $source,
                'resolved_at' => now()->toIso8601String(),
            ], $metadata);

            $exception->forceFill([
                'status' => MinorAccountLifecycleException::STATUS_RESOLVED,
                'source' => $source,
                'metadata' => $existingMetadata,
                'resolved_at' => now(),
            ])->save();

            $this->metricsCollector->recordMinorLifecycleExceptionResolved();

            new MinorAccountLifecycleExceptionResolved(
                $exception->id,
                $exception->minor_account_uuid,
                $exception->reason_code,
                $existingMetadata,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function nextActions(array $adultTransition, array $guardianContinuity, array $blockers): array
    {
        $actions = [];

        if (($adultTransition['reason_code'] ?? null) === MinorAccountLifecyclePolicy::REASON_ADULT_KYC_NOT_READY) {
            $actions[] = 'complete_adult_kyc';
        }

        if (! ($guardianContinuity['valid'] ?? true)) {
            $actions[] = 'restore_guardian_continuity';
        }

        if ($blockers !== []) {
            $actions[] = 'review_open_exceptions';
        }

        if ($actions === []) {
            $actions[] = 'monitor_lifecycle_schedule';
        }

        return $actions;
    }

    private function resolveTenantId(Account $minorAccount): string
    {
        $tenantId = AccountMembership::query()
            ->forAccount($minorAccount->uuid)
            ->active()
            ->value('tenant_id');

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : 'unknown-tenant';
    }
}
