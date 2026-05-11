<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Cards;

use App\Domain\CardSubscriptions\Enums\CardDisputeStatus;
use App\Domain\CardSubscriptions\Enums\CardFeeStatus;
use App\Domain\CardSubscriptions\Enums\CardFeeType;
use App\Domain\CardSubscriptions\Enums\CardRiskEventStatus;
use App\Domain\CardSubscriptions\Enums\CardSubscriptionStatus;
use App\Domain\CardSubscriptions\Enums\PhysicalCardOrderStatus;
use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Domain\CardSubscriptions\Models\CardFee;
use App\Domain\CardSubscriptions\Models\CardRiskEvent;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CardsOperationsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $activeSubs = CardSubscription::query()->where('status', CardSubscriptionStatus::Active)->count();
        $pastDue = CardSubscription::query()->where('status', CardSubscriptionStatus::PastDue)->count();

        $mrr = CardFee::query()
            ->where('fee_type', CardFeeType::Subscription)
            ->where('status', CardFeeStatus::Charged)
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('amount');

        $openDisputes = CardDispute::query()->whereIn('status', [
            CardDisputeStatus::Submitted,
            CardDisputeStatus::InReview,
            CardDisputeStatus::EvidenceRequired,
        ])->count();

        $openRisk = CardRiskEvent::query()->whereIn('status', [
            CardRiskEventStatus::Open,
            CardRiskEventStatus::InReview,
        ])->count();

        $physicalQueued = PhysicalCardOrder::query()->whereIn('order_status', [
            PhysicalCardOrderStatus::Requested,
            PhysicalCardOrderStatus::Paid,
            PhysicalCardOrderStatus::Approved,
            PhysicalCardOrderStatus::Production,
        ])->count();

        return [
            Stat::make('Active subscriptions', (string) $activeSubs),
            Stat::make('Past due', (string) $pastDue),
            Stat::make('Subscription fees charged (30d)', (string) $mrr . ' SZL'),
            Stat::make('Open disputes', (string) $openDisputes),
            Stat::make('Open / in-review risk events', (string) $openRisk),
            Stat::make('Physical orders (pipeline)', (string) $physicalQueued),
        ];
    }
}
