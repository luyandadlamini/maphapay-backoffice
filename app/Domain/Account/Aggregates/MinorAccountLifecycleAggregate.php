<?php

declare(strict_types=1);

namespace App\Domain\Account\Aggregates;

use App\Domain\Account\Events\MinorAccountAdultTransitionCompleted;
use App\Domain\Account\Events\MinorAccountAdultTransitionFrozen;
use App\Domain\Account\Events\MinorAccountGuardianContinuityBroken;
use App\Domain\Account\Events\MinorAccountGuardianContinuityRestored;
use App\Domain\Account\Events\MinorAccountTierAdvanceBlocked;
use App\Domain\Account\Events\MinorAccountTierAdvanced;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorAccountLifecycleTransition;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class MinorAccountLifecycleAggregate extends AggregateRoot
{
    protected string $accountUuid = '';

    protected string $tier = '';

    protected string $permissionLevel = '';

    protected string $minorTransitionState = '';

    protected ?\Illuminate\Support\Carbon $minorTransitionEffectiveAt = null;

    protected bool $frozen = false;

    public static function initialize(string $uuid): static
    {
        $instance = static::retrieve($uuid);
        $instance->hydrateFromAccount($uuid);

        return $instance;
    }

    private function hydrateFromAccount(string $uuid): void
    {
        $account = Account::query()
            ->where('uuid', $uuid)
            ->first();

        if ($account) {
            $this->accountUuid = $uuid;
            $this->tier = (string) $account->tier;
            $this->permissionLevel = (string) $account->permission_level;
            $this->minorTransitionState = (string) $account->minor_transition_state;
            $this->minorTransitionEffectiveAt = $account->minor_transition_effective_at;
            $this->frozen = (bool) $account->frozen;
        }
    }

    public function transitionToTier(
        string $targetTier,
        string $targetPermissionLevel,
        ?string $effectiveAt = null,
    ): static {
        $this->recordThat(
            new MinorAccountTierAdvanced(
                minorAccountUuid: $this->accountUuid,
                fromTier: $this->tier,
                toTier: $targetTier,
                metadata: [
                    'target_permission_level' => $targetPermissionLevel,
                    'effective_at'            => $effectiveAt,
                ],
            )
        );

        return $this;
    }

    public function applyMinorAccountTierAdvanced(MinorAccountTierAdvanced $event): void
    {
        $this->tier = $event->toTier;
        $this->minorTransitionState = MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE;
        if (isset($event->metadata['effective_at'])) {
            $this->minorTransitionEffectiveAt = $event->metadata['effective_at'] instanceof \Illuminate\Support\Carbon
                ? $event->metadata['effective_at']
                : \Illuminate\Support\Carbon::parse($event->metadata['effective_at']);
        }
    }

    public function blockTierAdvance(string $reasonCode): static
    {
        $this->recordThat(
            new MinorAccountTierAdvanceBlocked(
                minorAccountUuid: $this->accountUuid,
                reasonCode: $reasonCode,
            )
        );

        return $this;
    }

    public function applyMinorAccountTierAdvanceBlocked(MinorAccountTierAdvanceBlocked $event): void
    {
        $this->minorTransitionState = 'blocked';
        $this->minorTransitionEffectiveAt = now();
    }

    public function transitionToAdult(string $effectiveAt): static
    {
        $this->recordThat(
            new MinorAccountAdultTransitionCompleted(
                minorAccountUuid: $this->accountUuid,
                metadata: [
                    'effective_at' => $effectiveAt,
                ],
            )
        );

        return $this;
    }

    public function applyMinorAccountAdultTransitionCompleted(MinorAccountAdultTransitionCompleted $event): void
    {
        $this->minorTransitionState = 'adult_transition_completed';
        $this->minorTransitionEffectiveAt = isset($event->metadata['effective_at'])
            ? \Illuminate\Support\Carbon::parse($event->metadata['effective_at'])
            : now();
        $this->frozen = false;
    }

    public function freezeForAdultTransition(string $reasonCode, ?string $effectiveAt = null): static
    {
        $this->recordThat(
            new MinorAccountAdultTransitionFrozen(
                minorAccountUuid: $this->accountUuid,
                reasonCode: $reasonCode,
                metadata: [
                    'effective_at' => $effectiveAt,
                ],
            )
        );

        return $this;
    }

    public function applyMinorAccountAdultTransitionFrozen(MinorAccountAdultTransitionFrozen $event): void
    {
        $this->minorTransitionState = 'adult_transition_frozen';
        $this->minorTransitionEffectiveAt = isset($event->metadata['effective_at'])
            ? \Illuminate\Support\Carbon::parse($event->metadata['effective_at'])
            : now();
        $this->frozen = true;
    }

    public function breakGuardianContinuity(string $reasonCode, ?string $effectiveAt = null): static
    {
        $this->recordThat(
            new MinorAccountGuardianContinuityBroken(
                minorAccountUuid: $this->accountUuid,
                reasonCode: $reasonCode,
                metadata: [
                    'effective_at' => $effectiveAt,
                ],
            )
        );

        return $this;
    }

    public function applyMinorAccountGuardianContinuityBroken(MinorAccountGuardianContinuityBroken $event): void
    {
        $this->minorTransitionState = 'guardian_review_blocked';
        $this->minorTransitionEffectiveAt = isset($event->metadata['effective_at'])
            ? \Illuminate\Support\Carbon::parse($event->metadata['effective_at'])
            : now();
        $this->frozen = true;
    }

    public function restoreGuardianContinuity(): static
    {
        $this->recordThat(
            new MinorAccountGuardianContinuityRestored(
                minorAccountUuid: $this->accountUuid,
            )
        );

        return $this;
    }

    public function applyMinorAccountGuardianContinuityRestored(MinorAccountGuardianContinuityRestored $event): void
    {
        $this->minorTransitionState = '';
        $this->minorTransitionEffectiveAt = null;
        $this->frozen = false;
    }

    public function persist(): static
    {
        parent::persist();

        if ($this->accountUuid !== '') {
            $this->applyToAccount();
        }

        return $this;
    }

    private function applyToAccount(): void
    {
        Account::query()
            ->where('uuid', $this->accountUuid)
            ->update([
                'tier'                          => $this->tier,
                'permission_level'              => $this->permissionLevel,
                'minor_transition_state'        => $this->minorTransitionState,
                'minor_transition_effective_at' => $this->minorTransitionEffectiveAt,
                'frozen'                        => $this->frozen,
            ]);
    }
}
