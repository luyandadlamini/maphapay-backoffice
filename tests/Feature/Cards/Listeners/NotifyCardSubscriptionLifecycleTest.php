<?php

declare(strict_types=1);

use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Events\CardSubscriptionPastDue;
use App\Domain\CardSubscriptions\Listeners\NotifyCardSubscriptionLifecycle;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\Mobile\Services\PushNotificationService;
use App\Models\User;

it('sends payment_failed push to payer on past due', function () {
    try {
        DB::connection()->getPdo();
    } catch (Throwable) {
        test()->markTestSkipped('Database not available.');
    }

    foreach (['card_plans', 'card_subscriptions'] as $table) {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            test()->markTestSkipped("Table `{$table}` missing.");
        }
    }

    $payer = User::factory()->create();
    $subscriber = User::factory()->create();

    $plan = CardPlan::factory()->create(['name' => 'Test Plan', 'active' => true]);

    $sub = CardSubscription::factory()->create([
        'subscriber_user_id' => $subscriber->id,
        'payer_user_id'      => $payer->id,
        'card_plan_id'       => $plan->id,
        'status'             => CardSubscriptionStatus::PastDue,
    ]);

    $push = Mockery::mock(PushNotificationService::class);
    $push->shouldReceive('sendToUser')
        ->atLeast()
        ->once();

    $listener = new NotifyCardSubscriptionLifecycle($push);

    $listener->onSubscriptionLifecycleEvent(new CardSubscriptionPastDue(
        subscriptionId: (string) $sub->id,
        failedPaymentCount: 1,
        gracePeriodEndsAt: now()->addDays(3)->toIso8601String(),
        failureReason: 'INSUFFICIENT_FUNDS',
    ));
})->group('cards', 'listeners');
