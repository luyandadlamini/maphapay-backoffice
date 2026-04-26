<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\User\Models\UserProfile;
use App\Models\User;
use Illuminate\Support\Carbon;

class MinorAccountLifecyclePolicy
{
    public const REASON_MISSING_DATE_OF_BIRTH = 'missing_date_of_birth';

    public const REASON_TIER_ADVANCE_DUE = 'tier_advance_due';

    public const REASON_ADULT_KYC_NOT_READY = 'adult_kyc_not_ready';

    public const REASON_GUARDIAN_CONTINUITY_BROKEN = 'guardian_continuity_broken';

    public const REASON_PRIMARY_GUARDIAN_INELIGIBLE = 'primary_guardian_ineligible';

    /**
     * @return array{date_of_birth:?Carbon, age:?int, target_tier:?string, target_permission_level:?int}
     */
    public function ageContext(Account $minorAccount): array
    {
        $dateOfBirth = $this->dateOfBirth($minorAccount);
        $age = $dateOfBirth?->age;

        return [
            'date_of_birth'           => $dateOfBirth,
            'age'                     => $age,
            'target_tier'             => $age === null ? null : $this->targetTierForAge($age),
            'target_permission_level' => $age === null ? null : $this->permissionLevelForAge($age),
        ];
    }

    /**
     * @return array{eligible:bool, reason_code:?string, target_tier:?string, target_permission_level:?int}
     */
    public function evaluateTierAdvance(Account $minorAccount): array
    {
        $ageContext = $this->ageContext($minorAccount);

        if ($ageContext['date_of_birth'] === null || $ageContext['age'] === null) {
            return [
                'eligible'                => false,
                'reason_code'             => self::REASON_MISSING_DATE_OF_BIRTH,
                'target_tier'             => null,
                'target_permission_level' => null,
            ];
        }

        $targetTier = $ageContext['target_tier'];
        $targetPermissionLevel = $ageContext['target_permission_level'];
        $currentPermissionLevel = (int) ($minorAccount->permission_level ?? 0);

        $eligible = $targetTier !== null
            && $targetTier !== $minorAccount->tier
            && in_array($targetTier, ['grow', 'rise'], true);

        return [
            'eligible'                => $eligible,
            'reason_code'             => $eligible ? null : self::REASON_TIER_ADVANCE_DUE,
            'target_tier'             => $targetTier,
            'target_permission_level' => max($currentPermissionLevel, (int) $targetPermissionLevel),
        ];
    }

    /**
     * @return array{ready:bool, reason_code:?string, age:?int}
     */
    public function evaluateAdultTransition(Account $minorAccount): array
    {
        $ageContext = $this->ageContext($minorAccount);

        if ($ageContext['date_of_birth'] === null || $ageContext['age'] === null) {
            return [
                'ready'       => false,
                'reason_code' => self::REASON_MISSING_DATE_OF_BIRTH,
                'age'         => null,
            ];
        }

        if ($ageContext['age'] < 18) {
            return [
                'ready'       => false,
                'reason_code' => null,
                'age'         => $ageContext['age'],
            ];
        }

        $user = $minorAccount->user()->first();
        $ready = $user instanceof User && $user->kyc_status === 'approved';

        return [
            'ready'       => $ready,
            'reason_code' => $ready ? null : self::REASON_ADULT_KYC_NOT_READY,
            'age'         => $ageContext['age'],
        ];
    }

    /**
     * @return array{valid:bool, reason_code:?string, active_guardian_count:int}
     */
    public function evaluateGuardianContinuity(Account $minorAccount): array
    {
        $guardianMemberships = AccountMembership::query()
            ->forAccount($minorAccount->uuid)
            ->active()
            ->whereIn('role', ['guardian', 'co_guardian'])
            ->get();

        $activeGuardianCount = 0;
        foreach ($guardianMemberships as $membership) {
            $guardian = User::query()->where('uuid', $membership->user_uuid)->first();
            if ($guardian instanceof User && $guardian->frozen_at === null) {
                $activeGuardianCount++;
            }
        }

        if ($activeGuardianCount > 0) {
            return [
                'valid'                 => true,
                'reason_code'           => null,
                'active_guardian_count' => $activeGuardianCount,
            ];
        }

        return [
            'valid'                 => false,
            'reason_code'           => self::REASON_GUARDIAN_CONTINUITY_BROKEN,
            'active_guardian_count' => 0,
        ];
    }

    public function dateOfBirth(Account $minorAccount): ?Carbon
    {
        /** @var User|null $user */
        $user = $minorAccount->user()->first();
        if (! $user instanceof User) {
            return null;
        }

        /** @var UserProfile|null $profile */
        $profile = UserProfile::query()
            ->where('user_id', $user->id)
            ->first();

        return $profile?->date_of_birth?->copy()->startOfDay();
    }

    public function turning18Date(Account $minorAccount): ?Carbon
    {
        return $this->dateOfBirth($minorAccount)?->copy()->addYears(18);
    }

    public function targetTierForAge(int $age): ?string
    {
        return match (true) {
            $age < 6  => null,
            $age < 13 => 'grow',
            $age < 18 => 'rise',
            default   => 'adult_transition',
        };
    }

    public function permissionLevelForAge(int $age): int
    {
        return match (true) {
            $age <= 7  => 1,
            $age <= 9  => 2,
            $age <= 11 => 3,
            $age <= 13 => 4,
            $age <= 15 => 5,
            default    => 6,
        };
    }
}
