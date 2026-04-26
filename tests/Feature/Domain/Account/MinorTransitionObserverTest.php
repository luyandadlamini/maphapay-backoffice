<?php

declare(strict_types=1);

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorAccountLifecycleException;
use App\Domain\Account\Models\MinorAccountLifecycleTransition;

it('blocks deletion of a lifecycle transition that has referencing exceptions', function (): void {
    $account = Account::factory()->create(['type' => 'minor']);

    $transition = MinorAccountLifecycleTransition::query()->create([
        'tenant_id' => 'test-tenant',
        'minor_account_uuid' => $account->uuid,
        'transition_type' => MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE,
        'state' => MinorAccountLifecycleTransition::STATE_PENDING,
        'effective_at' => now(),
    ]);

    MinorAccountLifecycleException::query()->create([
        'tenant_id' => 'test-tenant',
        'minor_account_uuid' => $account->uuid,
        'transition_id' => $transition->id,
        'reason_code' => 'test_reason',
        'status' => 'open',
        'source' => 'test',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    expect(fn () => $transition->delete())
        ->toThrow(\RuntimeException::class, 'Cannot delete a lifecycle transition that has referencing exceptions');

    expect(MinorAccountLifecycleTransition::query()->where('id', $transition->id)->exists())->toBeTrue();
});

it('allows deletion of a transition with no referencing exceptions', function (): void {
    $account = Account::factory()->create(['type' => 'minor']);

    $transition = MinorAccountLifecycleTransition::query()->create([
        'tenant_id' => 'test-tenant',
        'minor_account_uuid' => $account->uuid,
        'transition_type' => MinorAccountLifecycleTransition::TYPE_TIER_ADVANCE,
        'state' => MinorAccountLifecycleTransition::STATE_PENDING,
        'effective_at' => now(),
    ]);

    expect(fn () => $transition->delete())->not->toThrow(\Exception::class);
    expect(MinorAccountLifecycleTransition::query()->where('id', $transition->id)->exists())->toBeFalse();
});