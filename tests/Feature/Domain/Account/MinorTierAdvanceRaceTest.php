<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorAccountLifecycleTransition;
use App\Domain\Account\Services\MinorAccountLifecyclePolicy;
use App\Domain\Account\Services\MinorAccountLifecycleService;

it('schedules at most one tier-advance transition even when evaluateAccount is called concurrently', function (): void {
    $account = Account::factory()->create([
        'type'             => 'minor',
        'tier'             => 'grow',
        'permission_level' => 4,
    ]);

    $policy = Mockery::mock(MinorAccountLifecyclePolicy::class);
    $policy->shouldReceive('evaluateTierAdvance')
        ->atLeast()->times(1)
        ->andReturn([
            'eligible'                => true,
            'target_tier'             => 'rise',
            'target_permission_level' => 5,
            'reason_code'             => null,
        ]);
    $policy->shouldReceive('evaluateGuardianContinuity')
        ->atLeast()->times(1)
        ->andReturn(['valid' => true, 'active_guardian_count' => 1]);
    $policy->shouldReceive('ageContext')->byDefault();
    $policy->shouldReceive('turning18Date')->byDefault();
    $policy->shouldReceive('evaluateAdultTransition')->byDefault();

    $this->app->instance(MinorAccountLifecyclePolicy::class, $policy);

    $service = app(MinorAccountLifecycleService::class);
    $service->evaluateAccount($account, 'test');
    $service->evaluateAccount($account, 'test');

    $transitions = MinorAccountLifecycleTransition::query()
        ->where('minor_account_uuid', $account->uuid)
        ->where('transition_type', MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE)
        ->count();

    expect($transitions)->toBe(1);
});
