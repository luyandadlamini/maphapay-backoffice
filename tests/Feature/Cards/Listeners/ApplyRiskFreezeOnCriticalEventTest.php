<?php

declare(strict_types=1);

use App\Domain\CardSubscriptions\Events\CardRiskEventOpened;
use App\Domain\CardSubscriptions\Listeners\ApplyRiskFreezeOnCriticalEvent;
use App\Domain\CardSubscriptions\Services\CardRiskService;
use App\Models\User;

it('invokes suspendCardsForUser on high severity risk events', function () {
    $user = User::factory()->create();

    $risk = Mockery::mock(CardRiskService::class);
    $risk->shouldReceive('suspendCardsForUser')
        ->once()
        ->with(Mockery::on(fn (User $u) => $u->is($user)), 'velocity.declines_10min');

    $listener = new ApplyRiskFreezeOnCriticalEvent($risk);

    $listener->onCardRiskEventOpened(new CardRiskEventOpened(
        riskEventId: 'evt_1',
        userId: (string) $user->id,
        cardId: null,
        eventType: 'velocity.declines_10min',
        severity: 'high',
    ));
})->group('cards', 'listeners');
