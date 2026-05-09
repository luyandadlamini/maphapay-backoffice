<?php

declare(strict_types=1);

use App\Domain\CardSubscriptions\Events\CardDisputeResolved;
use App\Domain\CardSubscriptions\Events\CardDisputeSubmitted;
use App\Domain\CardSubscriptions\Events\CardFeeCharged;
use App\Domain\CardSubscriptions\Events\CardFeeRefunded;
use App\Domain\CardSubscriptions\Events\CardFeeWaived;
use App\Domain\CardSubscriptions\Events\CardRiskEventOpened;
use App\Domain\CardSubscriptions\Events\CardRiskEventResolved;
use App\Domain\CardSubscriptions\Events\CardSubscriptionActivated;
use App\Domain\CardSubscriptions\Events\CardSubscriptionBillingAttempted;
use App\Domain\CardSubscriptions\Events\CardSubscriptionCancelled;
use App\Domain\CardSubscriptions\Events\CardSubscriptionPastDue;
use App\Domain\CardSubscriptions\Events\CardSubscriptionPlanChanged;
use App\Domain\CardSubscriptions\Events\CardSubscriptionRestored;
use App\Domain\CardSubscriptions\Events\CardSubscriptionSuspended;
use App\Domain\CardSubscriptions\Events\MinorCardRequestApproved;
use App\Domain\CardSubscriptions\Events\MinorCardRequestDenied;
use App\Domain\CardSubscriptions\Events\PhysicalCardOrderActivated;
use App\Domain\CardSubscriptions\Events\PhysicalCardOrderCancelled;
use App\Domain\CardSubscriptions\Events\PhysicalCardOrderRequested;
use App\Domain\CardSubscriptions\Events\PhysicalCardOrderStatusChanged;
use App\Domain\CardSubscriptions\Models\CardAuditLog;
use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Domain\CardSubscriptions\Models\CardFee;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardRiskEvent;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\CardSubscriptionBillingAttempt;
use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use App\Domain\CardSubscriptions\Services\CardBillingService;
use App\Domain\CardSubscriptions\Services\CardDisputeService;
use App\Domain\CardSubscriptions\Services\CardEntitlementService;
use App\Domain\CardSubscriptions\Services\CardFeeService;
use App\Domain\CardSubscriptions\Services\CardLifecycleService;
use App\Domain\CardSubscriptions\Services\CardRevealService;
use App\Domain\CardSubscriptions\Services\CardRiskService;
use App\Domain\CardSubscriptions\Services\CardSubscriptionService;
use App\Domain\CardSubscriptions\Services\MinorCardSubscriptionService;
use App\Domain\CardSubscriptions\Services\PhysicalCardOrderService;

it('resolves card subscription domain services from the container', function (): void {
    $services = [
        CardEntitlementService::class,
        CardSubscriptionService::class,
        CardBillingService::class,
        CardFeeService::class,
        CardLifecycleService::class,
        CardRiskService::class,
        CardAuditService::class,
        CardRevealService::class,
        CardDisputeService::class,
        PhysicalCardOrderService::class,
        MinorCardSubscriptionService::class,
    ];

    foreach ($services as $service) {
        expect(app($service))->toBeInstanceOf($service);
    }
});

it('instantiates all card subscription stored events with sample data', function (): void {
    $events = [
        new CardSubscriptionActivated('sub_1', 'user_1', 'user_2', 'premium', '49.00', '2026-05-01T00:00:00+02:00', '2026-06-01T00:00:00+02:00', false, null),
        new CardSubscriptionPlanChanged('sub_1', 'basic', 'premium', '12.50'),
        new CardSubscriptionPastDue('sub_1', 1, '2026-05-15T00:00:00+02:00', 'insufficient_funds'),
        new CardSubscriptionSuspended('sub_1', '2026-05-16T00:00:00+02:00'),
        new CardSubscriptionCancelled('sub_1', '2026-05-17T00:00:00+02:00', 'system'),
        new CardSubscriptionRestored('sub_1', '2026-05-18T00:00:00+02:00'),
        new CardSubscriptionBillingAttempted('sub_1', 'attempt_1', 'failed', '49.00', 'insufficient_funds'),
        new CardFeeCharged('fee_1', 'user_1', 'subscription', '49.00', 'card_subscription', 'sub_1'),
        new CardFeeWaived('fee_1', 'admin_1', 'goodwill'),
        new CardFeeRefunded('fee_1', 'admin_1', 'duplicate_charge'),
        new CardRiskEventOpened('risk_1', 'user_1', 'card_1', 'decline_velocity', 'critical'),
        new CardRiskEventResolved('risk_1', 'admin_1', 'Reviewed and closed.'),
        new CardDisputeSubmitted('dispute_1', 'user_1', 'txn_1', 'fraud', '25.00'),
        new CardDisputeResolved('dispute_1', 'won', 'Issuer accepted dispute.'),
        new PhysicalCardOrderRequested('order_1', 'user_1', 'courier', '85.00'),
        new PhysicalCardOrderStatusChanged('order_1', 'requested', 'dispatched'),
        new PhysicalCardOrderActivated('order_1', 'card_1'),
        new PhysicalCardOrderCancelled('order_1', 'user_cancelled', true),
        new MinorCardRequestApproved('request_1', 'minor_account_1', 'guardian_1', 'subscribe'),
        new MinorCardRequestDenied('request_1', 'minor_account_1', 'guardian_1', 'Not appropriate yet.'),
    ];

    expect($events)->toHaveCount(20);
});

it('can make card subscription domain models from factories without database writes', function (): void {
    $models = [
        CardPlan::class => [],
        CardSubscription::class => [
            'subscriber_user_id' => '00000000-0000-0000-0000-000000000001',
            'payer_user_id' => '00000000-0000-0000-0000-000000000002',
            'card_plan_id' => '00000000-0000-0000-0000-000000000003',
        ],
        CardSubscriptionBillingAttempt::class => [
            'card_subscription_id' => '00000000-0000-0000-0000-000000000004',
        ],
        CardFee::class => [
            'user_id' => '00000000-0000-0000-0000-000000000005',
        ],
        CardAuditLog::class => [],
        CardRiskEvent::class => [
            'user_id' => '00000000-0000-0000-0000-000000000006',
        ],
        CardDispute::class => [
            'user_id' => '00000000-0000-0000-0000-000000000007',
            'card_transaction_id' => '00000000-0000-0000-0000-000000000008',
        ],
        PhysicalCardOrder::class => [
            'user_id' => '00000000-0000-0000-0000-000000000009',
            'card_subscription_id' => '00000000-0000-0000-0000-000000000010',
        ],
    ];

    foreach ($models as $model => $overrides) {
        expect($model::factory()->make($overrides))->toBeInstanceOf($model);
    }
});
