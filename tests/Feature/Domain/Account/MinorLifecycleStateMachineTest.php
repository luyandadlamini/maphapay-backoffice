<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorAccountLifecycleTransition;

it('allows PENDING to transition to COMPLETED', function (): void {
    $account = Account::factory()->create(['type' => 'minor']);

    $transition = MinorAccountLifecycleTransition::factory()->create([
        'minor_account_uuid' => $account->uuid,
        'state'              => MinorAccountLifecycleTransition::STATE_PENDING,
    ]);

    expect(fn () => $transition->forceFill(['state' => MinorAccountLifecycleTransition::STATE_COMPLETED])->save())
        ->not->toThrow(\App\Domain\Account\Exceptions\InvalidLifecycleStateTransitionException::class);

    expect($transition->fresh()->state)->toBe(MinorAccountLifecycleTransition::STATE_COMPLETED);
});

it('allows PENDING to transition to BLOCKED', function (): void {
    $account = Account::factory()->create(['type' => 'minor']);

    $transition = MinorAccountLifecycleTransition::factory()->create([
        'minor_account_uuid' => $account->uuid,
        'state'              => MinorAccountLifecycleTransition::STATE_PENDING,
    ]);

    expect(fn () => $transition->forceFill(['state' => MinorAccountLifecycleTransition::STATE_BLOCKED])->save())
        ->not->toThrow(\App\Domain\Account\Exceptions\InvalidLifecycleStateTransitionException::class);

    expect($transition->fresh()->state)->toBe(MinorAccountLifecycleTransition::STATE_BLOCKED);
});

it('blocks COMPLETED from regressing to PENDING', function (): void {
    $account = Account::factory()->create(['type' => 'minor']);

    $transition = MinorAccountLifecycleTransition::factory()->create([
        'minor_account_uuid' => $account->uuid,
        'state'              => MinorAccountLifecycleTransition::STATE_PENDING,
    ]);

    $transition->forceFill(['state' => MinorAccountLifecycleTransition::STATE_COMPLETED])->save();

    expect(fn () => $transition->forceFill(['state' => MinorAccountLifecycleTransition::STATE_PENDING])->save())
        ->toThrow(\App\Domain\Account\Exceptions\InvalidLifecycleStateTransitionException::class);
});

it('blocks BLOCKED from advancing to COMPLETED', function (): void {
    $account = Account::factory()->create(['type' => 'minor']);

    $transition = MinorAccountLifecycleTransition::factory()->create([
        'minor_account_uuid' => $account->uuid,
        'state'              => MinorAccountLifecycleTransition::STATE_PENDING,
    ]);

    $transition->forceFill(['state' => MinorAccountLifecycleTransition::STATE_BLOCKED])->save();

    expect(fn () => $transition->forceFill(['state' => MinorAccountLifecycleTransition::STATE_COMPLETED])->save())
        ->toThrow(\App\Domain\Account\Exceptions\InvalidLifecycleStateTransitionException::class);
});

it('blocks new records from starting in a non-PENDING state', function (): void {
    $account = Account::factory()->create(['type' => 'minor']);

    expect(fn () => MinorAccountLifecycleTransition::factory()->create([
        'minor_account_uuid' => $account->uuid,
        'state'              => MinorAccountLifecycleTransition::STATE_COMPLETED,
    ]))->toThrow(\App\Domain\Account\Exceptions\InvalidLifecycleStateTransitionException::class);
});
