<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorCardRequest;
use App\Domain\Account\Models\MinorChore;
use App\Domain\Account\Models\MinorReward;
use App\Domain\Account\Policies\ChorePolicy;
use App\Domain\Account\Policies\MinorCardPolicy;
use App\Domain\Account\Policies\RewardPolicy;
use App\Domain\Analytics\Models\RevenueTarget;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Models\CardAuditLog;
use App\Domain\CardSubscriptions\Models\CardDispute;
use App\Domain\CardSubscriptions\Models\CardPlan;
use App\Domain\CardSubscriptions\Models\CardRiskEvent;
use App\Domain\CardSubscriptions\Models\CardSubscription;
use App\Domain\CardSubscriptions\Models\CardTransaction;
use App\Domain\CardSubscriptions\Models\PhysicalCardOrder;
use App\Policies\AccountPolicy;
use App\Policies\Cards\CardAuditLogPolicy;
use App\Policies\Cards\CardDisputePolicy;
use App\Policies\Cards\CardPlanPolicy;
use App\Policies\Cards\CardPolicy;
use App\Policies\Cards\CardRiskEventPolicy;
use App\Policies\Cards\CardSubscriptionPolicy;
use App\Policies\Cards\CardTransactionPolicy;
use App\Policies\Cards\PhysicalCardOrderPolicy;
use App\Policies\RevenueTargetPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Account::class          => AccountPolicy::class,
        RevenueTarget::class    => RevenueTargetPolicy::class,
        MinorChore::class       => ChorePolicy::class,
        MinorReward::class      => RewardPolicy::class,
        MinorCardRequest::class => MinorCardPolicy::class,
        // Cards domain
        CardSubscription::class  => CardSubscriptionPolicy::class,
        Card::class              => CardPolicy::class,
        CardTransaction::class   => CardTransactionPolicy::class,
        CardDispute::class       => CardDisputePolicy::class,
        CardRiskEvent::class     => CardRiskEventPolicy::class,
        PhysicalCardOrder::class => PhysicalCardOrderPolicy::class,
        CardAuditLog::class      => CardAuditLogPolicy::class,
        CardPlan::class          => CardPlanPolicy::class,
    ];
}
